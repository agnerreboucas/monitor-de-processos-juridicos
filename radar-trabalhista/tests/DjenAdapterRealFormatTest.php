<?php
declare(strict_types=1);

namespace Radar\Tests;

use PHPUnit\Framework\TestCase;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\HttpResponse;
use Radar\Support\Cnj;
use Radar\Support\Tribunals;
use Radar\Tests\Support\FakeHttp;

/**
 * Valida o adaptador contra um retorno REAL da API Comunica/DJEN (amostra de 13 itens de uma consulta ampla
 * de 12/10/2026, escolhidos para cobrir tribunais, tipos e formatos de texto diferentes). O que NÃO é provado
 * aqui: a chamada ao vivo (rede, bloqueio por IP, limites) — isso só se vê rodando no Brasil.
 */
final class DjenAdapterRealFormatTest extends TestCase
{
    /** @return array{status:string,count:int,items:list<array<string,mixed>>} */
    private function payload(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/djen-real-sample.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function http(?array $payload = null): FakeHttp
    {
        return (new FakeHttp())->push(new HttpResponse(200, json_encode($payload ?? $this->payload(), JSON_THROW_ON_ERROR)));
    }

    /** @return array<int,array<string,mixed>> itens por id */
    private function byId(): array
    {
        $out = [];
        foreach ($this->payload()['items'] as $item) {
            $out[$item['id']] = $item;
        }
        return $out;
    }

    public function testEveryRealItemBecomesAPublicationWithAValidCnj(): void
    {
        $count = count($this->payload()['items']);
        $publications = (new DjenAdapter($this->http()))->fetchByOab('128733', 'SP', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-12'));

        $this->assertCount($count, $publications);
        $this->assertCount($count, array_unique(array_map(fn ($p) => $p->externalId, $publications)));
        foreach ($publications as $p) {
            $this->assertNotNull(Cnj::parse($p->cnjDigits), $p->externalId);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $p->publishedAt);
            $this->assertNotSame('', $p->description, $p->externalId);
            $this->assertDoesNotMatchRegularExpression('/<\/?[a-z][^>]*>|&[a-z]+;/i', $p->description, $p->externalId . ' ficou com tag ou entidade');
            $this->assertNotNull($p->descriptionRaw);
        }
    }

    public function testRealHtmlTextIsCleanedAndTheOriginalIsKept(): void
    {
        $item = $this->byId()[719319131];                       // TJTO, texto em <html><head><style>…
        $this->assertStringContainsString('<html', strtolower($item['texto']));
        $p = (new DjenAdapter($this->http()))->fetchByOab('1', 'TO', new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'))[0];
        $this->assertSame('djen-719319131', $p->externalId);
        $this->assertSame('2026-10-12', $p->publishedAt);
        $this->assertSame('Edital', $p->kind);
        $this->assertStringNotContainsString('{', explode("\n", $p->description)[0]);   // sem CSS vazando
        $this->assertSame($item['texto'], $p->descriptionRaw);
        $this->assertStringStartsWith('https://eproc1.tjto.jus.br/', (string) $p->sourceUrl);
        $this->assertStringContainsString('?', (string) $p->sourceUrl);                  // link com querystring é mantido inteiro
    }

    public function testMissingLinkBecomesNullAndPlainTextWithBreaksSurvives(): void
    {
        $all = array_column((new DjenAdapter($this->http()))->fetchByOab('1', 'SC', new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12')), null, 'externalId');
        $this->assertNull($this->byId()[732389556]['link']);
        $this->assertNull($all['djen-732389556']->sourceUrl);
        $this->assertNotSame('', $all['djen-732389556']->description);                  // "segredo de justiça" também normaliza
        $this->assertStringNotContainsString('<br', strtolower($all['djen-677875913']->description));   // texto com <br />
        $this->assertStringContainsString("\n", $all['djen-677875913']->description);
    }

    public function testUnusualKindsAndHugeTextsAreHandled(): void
    {
        $all = array_column((new DjenAdapter($this->http()))->fetchByOab('1', 'SP', new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12')), null, 'externalId');
        $this->assertSame('Lista de distribuição', $all['djen-750447244']->kind);
        $this->assertSame('Citação', $all['djen-749568349']->kind);
        $this->assertGreaterThan(20000, strlen($all['djen-749698758']->description));    // texto grande do TST fica inteiro
        $this->assertSame([], $this->byId()[687179689]['destinatarioadvogados']);        // sem advogados: ainda é publicação válida
        $this->assertArrayHasKey('djen-687179689', $all);
    }

    /** O tribunal/UF vêm do número CNJ (fonte confiável); a sigla da fonte pode divergir (TST, STJ, CJF, TJM). */
    public function testTribunalAndUfComeFromTheCnjNotFromTheSourceAcronym(): void
    {
        $byId = $this->byId();
        $expected = [   // id => [sigla vinda da fonte, tribunal pelo CNJ, UF classificada]
            719319131 => ['TJTO', 'TJTO', 'TO'], 751951120 => ['TRT8', 'TRT8', null], 749698758 => ['TST', 'TRT4', 'RS'],
            751938234 => ['STJ', 'TJDFT', 'DF'], 751910377 => ['CJF', 'TRF1', null], 751899653 => ['TJMMG', 'TJM-MG', 'MG'],
            750971164 => ['TRF6', 'TRF6', 'MG'], 749568349 => ['TRF4', 'TRF4', null], 751756275 => ['STM', 'STM', null],
        ];
        $publications = array_column((new DjenAdapter($this->http()))->fetchByOab('1', 'SP', new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12')), null, 'externalId');
        foreach ($expected as $id => [$sigla, $tribunal, $uf]) {
            $this->assertSame($sigla, $publications["djen-$id"]->tribunal);
            $cnj = Cnj::parse($byId[$id]['numero_processo']);
            $this->assertNotNull($cnj);
            $this->assertSame($tribunal, $cnj->tribunal(), "tribunal de $id");
            $this->assertSame($uf, Tribunals::uf($cnj->tribunal()), "UF de $id");
        }
    }

    public function testByProcessNumberReturnsTheRealMovement(): void
    {
        $cnj = Cnj::parse('0001457-85.2026.5.08.0106') ?? throw new \LogicException();
        $payload = $this->payload();
        $payload['items'] = array_values(array_filter($payload['items'], fn ($i) => $i['numero_processo'] === $cnj->digits));
        $payload['count'] = count($payload['items']);
        $movements = (new DjenAdapter($this->http($payload)))->fetch($cnj, null);

        $this->assertCount(1, $movements);
        $this->assertSame('djen-751951120', $movements[0]->externalId);
        $this->assertSame('Intimação', $movements[0]->kind);
        $this->assertSame('https', parse_url((string) $movements[0]->sourceUrl, PHP_URL_SCHEME));
        $this->assertNotSame('', $movements[0]->description);
    }

    public function testBroadQueryWithCappedCountStopsWhenPagesRunOut(): void
    {
        // count=10000 (teto da API) mas só 13 itens: a página vem curta e a leitura para sem laço infinito.
        $http = $this->http();
        $got = (new DjenAdapter($http))->fetchByOab('1', 'SP', new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'));
        $this->assertCount(13, $got);
        $this->assertLessThanOrEqual(2, count($http->requests));
    }
}
