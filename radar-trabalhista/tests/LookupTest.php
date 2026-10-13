<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\HttpResponse;
use Radar\Monitoring\Worker;
use Radar\Service\LookupService;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;
use Radar\Tests\Support\FakeHttp;

final class LookupTest extends DbTestCase
{
    private function djenOk(array $items): HttpResponse
    {
        return new HttpResponse(200, json_encode(['count' => count($items), 'items' => $items], JSON_THROW_ON_ERROR));
    }

    private function djenItem(int $id, string $date): array
    {
        return ['id' => $id, 'data_disponibilizacao' => $date, 'tipoComunicacao' => 'Intimação', 'tipoDocumento' => 'Despacho', 'nomeOrgao' => 'Vara', 'nomeClasse' => 'X',
            'siglaTribunal' => 'TRT8', 'texto' => '<p>Intimação publicada.</p>', 'link' => 'https://pje.exemplo/' . $id, 'hash' => 'h' . $id];
    }

    private function datajudOk(): HttpResponse
    {
        return new HttpResponse(200, json_encode(['hits' => ['hits' => [['_source' => [
            'tribunal' => 'TRT8', 'grau' => 'G1', 'classe' => ['nome' => 'Ação Trabalhista'], 'assuntos' => [['nome' => 'Horas Extras']],
            'orgaoJulgador' => ['nome' => '5ª Vara'], 'dataAjuizamento' => '2026-01-20T00:00:00.000Z', 'nivelSigilo' => 0,
            'movimentos' => [['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-01-20T13:05:10.000Z'], ['codigo' => 51, 'nome' => 'Conclusão', 'dataHora' => '2026-10-05T09:00:00.000Z']],
        ]]]]], JSON_THROW_ON_ERROR));
    }

    private function service(?HttpResponse $djen, ?HttpResponse $datajud, bool $withDatajud = true): LookupService
    {
        $dj = (new FakeHttp());
        if ($djen !== null) {
            $dj->push($djen);
        }
        $da = (new FakeHttp());
        if ($datajud !== null) {
            $da->push($datajud);
        }
        return new LookupService($this->pdo, new DjenAdapter($dj), $withDatajud ? new DatajudAdapter($da, 'k') : null);
    }

    public function testMergesBothSourcesNewestFirst(): void
    {
        $o = $this->office('A', 'a@a.com');
        $r = $this->service($this->djenOk([$this->djenItem(1, '2026-10-09')]), $this->datajudOk())->lookup($this->ctx($o['user_id'], $o['tenant_id']), self::CNJ_TRT8);

        $this->assertSame('0001457-85.2026.5.08.0106', $r['cnj']);
        $this->assertSame('Ação Trabalhista', $r['case']['classe']);
        $this->assertSame(['ok', 'ok'], array_column($r['sources'], 'status'));
        $this->assertSame(['2026-10-09', '2026-10-05', '2026-01-20'], array_column($r['timeline'], 'date'));
        $this->assertSame(['djen', 'datajud', 'datajud'], array_column($r['timeline'], 'source'));
        $this->assertSame('https://pje.exemplo/1', $r['timeline'][0]['url']);
        $this->assertNull($r['monitored_id']);
    }

    public function testShowsPartiesAndLawyersFromTheDiario(): void
    {
        $o = $this->office('A', 'a@a.com');
        $item = $this->djenItem(1, '2026-10-09') + [
            'meiocompleto' => 'Diário de Justiça Eletrônico Nacional',
            'destinatarios' => [['nome' => 'ANA', 'polo' => 'A'], ['nome' => 'EMPRESA X', 'polo' => 'P']],
            'destinatarioadvogados' => [['advogado' => ['nome' => 'DR. FULANO', 'numero_oab' => '128733A', 'uf_oab' => 'sp']]],
        ];
        $r = $this->service($this->djenOk([$item, $item]), null, withDatajud: false)->lookup($this->ctx($o['user_id'], $o['tenant_id']), self::CNJ_TRT8);
        $this->assertCount(2, $r['parties']);                      // sem repetir a mesma parte de várias publicações
        $this->assertSame([['nome' => 'DR. FULANO', 'oab' => '128733A', 'uf' => 'SP']], $r['lawyers']);
        $this->assertSame('Diário de Justiça Eletrônico Nacional', $r['timeline'][0]['meio']);
    }

    public function testAFailingSourceIsReportedNeverHiddenAsEmpty(): void
    {
        $o = $this->office('A', 'a@a.com');
        $r = $this->service(new HttpResponse(403, ''), $this->datajudOk())->lookup($this->ctx($o['user_id'], $o['tenant_id']), self::CNJ_TRT8);
        $byCode = array_column($r['sources'], null, 'code');
        $this->assertSame('error', $byCode['djen']['status']);
        $this->assertNotEmpty($byCode['djen']['message']);
        $this->assertSame('ok', $byCode['datajud']['status']);
        $this->assertCount(2, $r['timeline']);                       // o que veio do Datajud aparece mesmo assim
    }

    public function testWithoutDatajudKeyItSaysSo(): void
    {
        $o = $this->office('A', 'a@a.com');
        $r = $this->service($this->djenOk([]), null, withDatajud: false)->lookup($this->ctx($o['user_id'], $o['tenant_id']), self::CNJ_TRT8);
        $this->assertSame('not_configured', array_column($r['sources'], null, 'code')['datajud']['status']);
        $this->assertStringContainsString('DATAJUD_API_KEY', array_column($r['sources'], null, 'code')['datajud']['message']);
    }

    public function testRejectsAnInvalidNumber(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->expectException(ValidationError::class);
        $this->service(null, null)->lookup($this->ctx($o['user_id'], $o['tenant_id']), '1234');
    }

    public function testShowsWhetherTheOfficeAlreadyMonitorsIt(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $id = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $o['user_id'])['id'];
        $r = $this->service($this->djenOk([]), $this->datajudOk())->lookup($ctx, self::CNJ_TRT8);
        $this->assertSame($id, $r['monitored_id']);
        $other = $this->office('B', 'b@b.com');                      // outro escritório não enxerga esse vínculo
        $r2 = $this->service($this->djenOk([]), $this->datajudOk())->lookup($this->ctx($other['user_id'], $other['tenant_id']), self::CNJ_TRT8);
        $this->assertNull($r2['monitored_id']);
    }

    public function testWorkerStoresBothSourcesAndKeepsGoingWhenDatajudFails(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $pid = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $o['user_id'])['id'];
        $djen = new FakeAdapter('djen');
        $datajud = new FakeAdapter('datajud');
        $djen->then([FakeAdapter::movement('1')]);
        $datajud->then([FakeAdapter::movement('a', 'Conclusão', '2026-10-05', 'datajud'), FakeAdapter::movement('b', 'Distribuição', '2026-01-20', 'datajud')]);
        $worker = new Worker($this->pdo, [$djen, $datajud], $this->clock, 'w1');
        $this->assertSame(1, $worker->runBatch(10)['succeeded']);
        $this->assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM process_movements WHERE process_id = ?', [$pid]));
        $this->assertSame(['datajud', 'djen'], $this->pdo->query('SELECT DISTINCT s.code FROM process_movements m JOIN integration_sources s ON s.id = m.source_id ORDER BY 1')->fetchAll(\PDO::FETCH_COLUMN));

        // segunda rodada: Datajud cai, o diário traz novidade → vale, com aviso no registro
        $this->clock->advance('PT7H');
        $this->assertSame(1, $worker->enqueueDue());
        $djen->then([FakeAdapter::movement('2')]);
        $datajud->then(new \Radar\Monitoring\SourceUnavailable('Datajud fora do ar.'));
        $r = $worker->runBatch(10);
        $this->assertSame(1, $r['succeeded']);
        $this->assertSame(1, $r['new_movements']);
        $msg = (string) $this->scalar('SELECT message FROM monitoring_events WHERE process_id = ? ORDER BY id DESC LIMIT 1', [$pid]);
        $this->assertStringContainsString('Datajud fora do ar', $msg);
    }

    public function testDiarioFailureStillFailsTheCheckEvenIfDatajudWorks(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $pid = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $o['user_id'])['id'];
        $djen = (new FakeAdapter('djen'))->then(new \Radar\Monitoring\SourceUnavailable('DJEN bloqueado.'));
        $datajud = (new FakeAdapter('datajud'))->then([FakeAdapter::movement('a', 'Conclusão', '2026-10-05', 'datajud')]);
        $r = (new Worker($this->pdo, [$djen, $datajud], $this->clock, 'w1'))->runBatch(10);
        $this->assertSame(1, $r['failed']);
        $this->assertNull($this->scalar('SELECT last_success_at FROM processes WHERE id = ?', [$pid]));   // não finge sucesso: pode ter perdido o diário
    }
}
