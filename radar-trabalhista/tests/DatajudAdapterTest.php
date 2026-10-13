<?php
declare(strict_types=1);

namespace Radar\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\HttpResponse;
use Radar\Monitoring\InvalidResponse;
use Radar\Monitoring\SourceUnavailable;
use Radar\Support\Cnj;
use Radar\Tests\Support\FakeHttp;

final class DatajudAdapterTest extends TestCase
{
    private function cnj(string $n = DbTestCase::CNJ_TRT8): Cnj
    {
        return Cnj::parse($n) ?? throw new \LogicException();
    }

    /** @param list<array<string,mixed>> $hits */
    private function ok(array $hits): HttpResponse
    {
        return new HttpResponse(200, json_encode(['hits' => ['hits' => array_map(fn ($s) => ['_source' => $s], $hits)]], JSON_THROW_ON_ERROR));
    }

    private function adapter(FakeHttp $http, string $key = 'chave-publica'): DatajudAdapter
    {
        return new DatajudAdapter($http, $key);
    }

    private function source(array $movimentos, array $extra = []): array
    {
        return $extra + ['tribunal' => 'TRT8', 'grau' => 'G1', 'classe' => ['nome' => 'Ação Trabalhista - Rito Ordinário'],
            'assuntos' => [['nome' => 'Rescisão do Contrato de Trabalho']], 'orgaoJulgador' => ['nome' => '5ª Vara do Trabalho de Belém'],
            'dataAjuizamento' => '2026-01-20T00:00:00.000Z', 'nivelSigilo' => 0, 'movimentos' => $movimentos];
    }

    public function testSendsTheRightIndexKeyAndNumber(): void
    {
        $http = (new FakeHttp())->push($this->ok([]));
        $this->adapter($http)->lookup($this->cnj());
        $this->assertSame('https://api-publica.datajud.cnj.jus.br/api_publica_trt8/_search', $http->posts[0]['url']);
        $this->assertSame('APIKey chave-publica', $http->posts[0]['headers']['Authorization']);
        $this->assertSame('00014578520265080106', $http->posts[0]['json']['query']['match']['numeroProcesso']);
    }

    public function testIndexNamesFollowTheTribunal(): void
    {
        $this->assertSame('api_publica_tjmg', DatajudAdapter::index($this->cnj('0022929-16.2016.8.13.0297')));
        $this->assertSame('api_publica_trf4', DatajudAdapter::index($this->cnj('5002305-61.2022.4.04.7109')));
        $this->assertSame('api_publica_stm', DatajudAdapter::index($this->cnj('7000133-21.2024.7.12.0012')));
    }

    public function testNormalizesTheFullHistoryWithCaseData(): void
    {
        $movs = [
            ['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-01-20T13:05:10.000Z'],
            ['codigo' => 51, 'nome' => 'Conclusão', 'dataHora' => '2026-02-03T00:01:00.000Z',
                'complementosTabelados' => [['codigo' => 1, 'nome' => 'tipo_de_conclusao', 'descricao' => 'para despacho']]],
            ['codigo' => 123, 'nome' => 'Arquivamento', 'dataHora' => '20260905101500'],           // formato compacto de alguns tribunais
        ];
        $r = $this->adapter((new FakeHttp())->push($this->ok([$this->source($movs)])))->lookup($this->cnj());

        $this->assertSame('Ação Trabalhista - Rito Ordinário', $r['classe']);
        $this->assertSame(['Rescisão do Contrato de Trabalho'], $r['assuntos']);
        $this->assertSame('5ª Vara do Trabalho de Belém', $r['orgao']);
        $this->assertSame('2026-01-20', $r['ajuizamento']);
        $this->assertCount(3, $r['movements']);
        [$a, $b, $c] = $r['movements'];
        $this->assertSame('2026-01-20', $a->occurredAt);
        $this->assertSame('13:05', $a->metadata['hora']);                                          // lido como escrito, sem fuso
        $this->assertSame('2026-02-03', $b->occurredAt);                                           // a data do ato não é deslocada pelo "Z"
        $this->assertSame('Conclusão — para despacho', $b->description);
        $this->assertSame('2026-09-05', $c->occurredAt);
        $this->assertSame('10:15', $c->metadata['hora']);
        $this->assertStringStartsWith('datajud-', $a->externalId);
    }

    public function testIdenticalActsInTheSameSecondKeepDistinctIds(): void
    {
        $m = ['codigo' => 581, 'nome' => 'Juntada de Petição', 'dataHora' => '2026-03-01T12:00:00.000Z'];
        $r = $this->adapter((new FakeHttp())->push($this->ok([$this->source([$m, $m, $m])])))->lookup($this->cnj());
        $ids = array_map(fn ($x) => $x->externalId, $r['movements']);
        $this->assertCount(3, array_unique($ids));
    }

    public function testSkipsBrokenMovementsAndMergesInstances(): void
    {
        $g1 = $this->source([['codigo' => 1, 'nome' => 'A', 'dataHora' => '2026-01-01T12:00:00Z'], ['codigo' => 2, 'dataHora' => '2026-01-02T12:00:00Z'], ['codigo' => 3, 'nome' => 'C', 'dataHora' => 'lixo']]);
        $g2 = $this->source([['codigo' => 1, 'nome' => 'A', 'dataHora' => '2026-01-01T12:00:00Z']], ['grau' => 'G2']);
        $r = $this->adapter((new FakeHttp())->push($this->ok([$g1, $g2])))->lookup($this->cnj());
        $this->assertSame(2, $r['instancias']);
        $this->assertCount(2, $r['movements']);                                                    // o ato igual em outro grau não é fundido
        $this->assertSame(['G1', 'G2'], array_map(fn ($x) => $x->metadata['grau'], $r['movements']));
    }

    public function testFetchIgnoresSinceAndReturnsEverything(): void
    {
        $http = (new FakeHttp())->push($this->ok([$this->source([['codigo' => 1, 'nome' => 'A', 'dataHora' => '2020-01-01T12:00:00Z']])]));
        $this->assertCount(1, $this->adapter($http)->fetch($this->cnj(), new \DateTimeImmutable('2026-01-01')));
    }

    public function testNoHitsMeansNotFoundNotError(): void
    {
        $this->assertNull($this->adapter((new FakeHttp())->push($this->ok([])))->lookup($this->cnj()));
        $this->assertSame([], $this->adapter((new FakeHttp())->push($this->ok([])))->fetch($this->cnj(), null));
    }

    #[DataProvider('failures')]
    public function testFailuresAreNeverEmptyResults(int $status, string $expected): void
    {
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessageMatches($expected);
        $this->adapter((new FakeHttp())->push(new HttpResponse($status, '{"error":"x"}')))->lookup($this->cnj());
    }

    public static function failures(): array
    {
        return ['chave recusada' => [401, '/recusou a chave/'], 'proibido' => [403, '/recusou a chave/'], 'limite' => [429, '/sobrecarregado/'],
            'servidor' => [503, '/sobrecarregado/'], 'rede' => [0, '/rede ou tempo/'], 'indice' => [404, '/índice/']];
    }

    public function testMissingKeyAndUnsupportedCourtAreExplained(): void
    {
        try {
            $this->adapter(new FakeHttp(), '')->lookup($this->cnj());
            $this->fail('deveria falhar sem chave');
        } catch (SourceUnavailable $e) {
            $this->assertStringContainsString('DATAJUD_API_KEY', $e->getMessage());
        }
        $this->expectException(SourceUnavailable::class);
        $this->adapter(new FakeHttp())->lookup($this->cnj(\Radar\Tests\Support\FakeOabSource::cnj(1, 2020, 1, '00', '0000')));   // STF
    }

    public function testGarbageIsAnInvalidResponse(): void
    {
        $this->expectException(InvalidResponse::class);
        $this->adapter((new FakeHttp())->push(new HttpResponse(200, '<html>oi</html>')))->lookup($this->cnj());
    }
}
