<?php
declare(strict_types=1);

namespace Radar\Tests;

use PHPUnit\Framework\TestCase;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\HttpResponse;
use Radar\Monitoring\InvalidResponse;
use Radar\Monitoring\SourceUnavailable;
use Radar\Support\Cnj;
use Radar\Tests\Support\FakeHttp;

final class DjenAdapterTest extends TestCase
{
    private function item(int $id, string $text = '<p>Fica intimada a parte.</p>'): array
    {
        return ['id' => $id, 'data_disponibilizacao' => '2026-10-09', 'tipoComunicacao' => 'Intimação',
            'tipoDocumento' => 'Despacho', 'nomeOrgao' => '5ª Vara', 'nomeClasse' => 'AÇÃO TRABALHISTA',
            'siglaTribunal' => 'TRT8', 'texto' => $text, 'link' => 'https://pje.exemplo/doc/' . $id, 'hash' => 'h' . $id];
    }

    private function ok(array $items, ?int $count = null): HttpResponse
    {
        return new HttpResponse(200, json_encode(['status' => 'success', 'count' => $count ?? count($items), 'items' => $items]));
    }

    private function cnj(): Cnj
    {
        return Cnj::parse(DbTestCase::CNJ_TRT8) ?? throw new \LogicException();
    }

    public function testNormalizesAPublication(): void
    {
        $http = (new FakeHttp())->push($this->ok([$this->item(77, '<style>.x{}</style><p>Fica <b>intimada</b> a parte&nbsp;para manifestar.</p><script>alert(1)</script>')]));
        $movements = (new DjenAdapter($http))->fetch($this->cnj(), null);

        $this->assertCount(1, $movements);
        $m = $movements[0];
        $this->assertSame('djen-77', $m->externalId);
        $this->assertSame('2026-10-09', $m->occurredAt);
        $this->assertSame('Intimação', $m->kind);
        $this->assertSame('Fica intimada a parte para manifestar.', $m->description);   // sem tags, script nem estilo
        $this->assertStringContainsString('<script>', (string) $m->descriptionRaw);       // o original fica guardado
        $this->assertSame('https://pje.exemplo/doc/77', $m->sourceUrl);
        $this->assertSame('5ª Vara', $m->metadata['orgao']);
    }

    public function testSendsTheProcessNumberAsDigitsAndTheSinceDate(): void
    {
        $http = new FakeHttp();
        (new DjenAdapter($http))->fetch($this->cnj(), new \DateTimeImmutable('2026-10-01'));
        $q = $http->requests[0]['query'];
        $this->assertSame('00014578520265080106', $q['numeroProcesso']);
        $this->assertSame('2026-10-01', $q['dataDisponibilizacaoInicio']);
        $this->assertSame(1, $q['pagina']);
        $this->assertSame('https://comunica.pje.jus.br/', $http->requests[0]['headers']['Referer']);
    }

    public function testFullHistoryStartsIn2015(): void
    {
        $http = new FakeHttp();
        (new DjenAdapter($http))->fetch($this->cnj(), null);
        $this->assertSame('2015-01-01', $http->requests[0]['query']['dataDisponibilizacaoInicio']);
    }

    public function testFollowsPagination(): void
    {
        $first = array_map(fn ($i) => $this->item($i), range(1, 100));
        $http = (new FakeHttp())->push($this->ok($first, 130))->push($this->ok([$this->item(101), $this->item(102)], 130));
        $movements = (new DjenAdapter($http))->fetch($this->cnj(), null);
        $this->assertCount(102, $movements);
        $this->assertCount(2, $http->requests);
        $this->assertSame(2, $http->requests[1]['query']['pagina']);
    }

    public function testNothingFoundIsAnEmptyListNotAnError(): void
    {
        foreach ([200 => '{"items":[],"count":0}', 400 => '{"message":"x"}', 404 => ''] as $status => $body) {
            $http = (new FakeHttp())->push(new HttpResponse($status, $body));
            $this->assertSame([], (new DjenAdapter($http))->fetch($this->cnj(), null), "HTTP $status");
        }
    }

    public function testUnavailableSourceIsAnErrorNotAnEmptyList(): void
    {
        foreach ([[0, 'timeout'], [429, ''], [500, ''], [503, ''], [502, ''], [418, '']] as [$status, $err]) {
            $http = (new FakeHttp())->push(new HttpResponse($status, '', $err !== '' ? $err : null));
            try {
                (new DjenAdapter($http))->fetch($this->cnj(), null);
                $this->fail("HTTP $status deveria falhar");
            } catch (SourceUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testForbiddenMentionsBrazil(): void
    {
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessageMatches('/Brasil/');
        (new DjenAdapter((new FakeHttp())->push(new HttpResponse(403, 'forbidden'))))->fetch($this->cnj(), null);
    }

    public function testGarbageIsInvalidResponse(): void
    {
        foreach (['<html>manutenção</html>', '"texto"', '{"items":"nao-e-lista"}', '{"items":[{"sem":"id"}],"count":1}'] as $body) {
            try {
                (new DjenAdapter((new FakeHttp())->push(new HttpResponse(200, $body))))->fetch($this->cnj(), null);
                $this->fail('deveria rejeitar: ' . $body);
            } catch (InvalidResponse) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyHttpsLinksAreKept(): void
    {
        $item = $this->item(5);
        $item['link'] = 'javascript:alert(1)';
        $m = (new DjenAdapter((new FakeHttp())->push($this->ok([$item]))))->fetch($this->cnj(), null)[0];
        $this->assertNull($m->sourceUrl);
    }

    public function testDateInBrazilianFormatAndInvalidDate(): void
    {
        $a = $this->item(1);
        unset($a['data_disponibilizacao']);
        $a['datadisponibilizacao'] = '09/10/2026';
        $b = $this->item(2);
        $b['data_disponibilizacao'] = '2026-13-45';
        $out = (new DjenAdapter((new FakeHttp())->push($this->ok([$a, $b]))))->fetch($this->cnj(), null);
        $this->assertSame('2026-10-09', $out[0]->occurredAt);
        $this->assertNull($out[1]->occurredAt);
    }
}
