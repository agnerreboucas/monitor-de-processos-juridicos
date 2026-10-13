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
        $http = (new FakeHttp())->push($this->ok($first, 130))->push($this->ok(array_map(fn ($i) => $this->item($i), range(101, 130)), 130));
        $movements = (new DjenAdapter($http))->fetch($this->cnj(), null);
        $this->assertCount(130, $movements);
        $this->assertCount(2, $http->requests);
        $this->assertSame(2, $http->requests[1]['query']['pagina']);
        $this->assertSame(100, $http->requests[0]['query']['itensPorPagina']);   // nome do parâmetro como na API Comunica
    }

    public function testKeepsPagingWhenTheServerCapsThePageSizeBelowWhatWasAsked(): void
    {
        $page = fn (int $from) => $this->ok(array_map(fn ($i) => $this->item($i), range($from, $from + 49)), 150);
        $http = (new FakeHttp())->push($page(1))->push($page(51))->push($page(101));
        $this->assertCount(150, (new DjenAdapter($http))->fetch($this->cnj(), null));
        $this->assertCount(3, $http->requests);
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

    // ── consulta por OAB ─────────────────────────────────────────────────────

    private function oabItem(int $id, string $cnj = DbTestCase::CNJ_TRT8): array
    {
        return $this->item($id) + ['numero_processo' => preg_replace('/\D/', '', $cnj)];
    }

    public function testOabFetchSplitsLongPeriodsIntoWindowsAndSendsOabParams(): void
    {
        $http = new FakeHttp();
        $pubs = (new DjenAdapter($http))->fetchByOab('012.345', 'mg', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-15'));
        $this->assertSame([], $pubs);
        $this->assertCount(3, $http->requests);                       // 31 + 31 + 13 dias
        $q = $http->requests[0]['query'];
        $this->assertSame('12345', ltrim((string) $q['numeroOab'], '0'));
        $this->assertSame('MG', $q['ufOab']);
        $this->assertSame('2026-01-01', $q['dataDisponibilizacaoInicio']);
        $this->assertSame('2026-01-31', $q['dataDisponibilizacaoFim']);
        $this->assertSame('2026-02-01', $http->requests[1]['query']['dataDisponibilizacaoInicio']);
        $this->assertSame('2026-03-15', $http->requests[2]['query']['dataDisponibilizacaoFim']);
    }

    public function testOabFetchReadsAllPagesAndSkipsItemsWithoutValidCnj(): void
    {
        $page1 = array_map(fn ($i) => $this->oabItem($i), range(1, 100));
        $http = (new FakeHttp())
            ->push($this->ok($page1, 101))
            ->push($this->ok([$this->oabItem(101, DbTestCase::CNJ_TJMG), $this->item(102) + ['numero_processo' => '123'], ['texto' => 'sem id']], 101));
        $pubs = (new DjenAdapter($http))->fetchByOab('1', 'SP', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-10'));
        $this->assertCount(101, $pubs);                                // o item com número inválido e o sem id ficam de fora
        $this->assertSame(2, $http->requests[1]['query']['pagina']);
        $this->assertSame('djen-101', $pubs[100]->externalId);
        $this->assertSame('TRT8', $pubs[0]->tribunal);
        $this->assertSame(preg_replace('/\D/', '', DbTestCase::CNJ_TJMG), $pubs[100]->cnjDigits);
    }

    public function testOabFetchRefusesToPretendItGotEverythingWhenVolumeIsTooBig(): void
    {
        $http = new FakeHttp();
        for ($i = 0; $i < 40; $i++) {
            $http->push($this->ok(array_map(fn ($n) => $this->oabItem($i * 100 + $n), range(1, 100)), 100000));
        }
        $this->expectException(SourceUnavailable::class);
        (new DjenAdapter($http))->fetchByOab('1', 'SP', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-10'));
    }

    public function testOabFetchPropagatesSourceFailureInsteadOfEmptyList(): void
    {
        $http = (new FakeHttp())->push(new HttpResponse(403, 'forbidden'));
        $this->expectException(SourceUnavailable::class);
        (new DjenAdapter($http))->fetchByOab('1', 'SP', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-10'));
    }
}
