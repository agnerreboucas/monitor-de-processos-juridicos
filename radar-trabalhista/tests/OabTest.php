<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Monitoring\OabMonitor;
use Radar\Monitoring\SourceUnavailable;
use Radar\Repository\OabPublicationRepository;
use Radar\Service\OabService;
use Radar\Service\OabStats;
use Radar\Support\Tribunals;
use Radar\Tests\Support\FakeOabSource as F;

final class OabTest extends DbTestCase
{
    private array $office;
    private F $source;
    private OabMonitor $monitor;
    private OabService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = $this->office('A', 'a@a.com', 'profissional');
        $this->source = new F();
        $this->monitor = new OabMonitor($this->pdo, $this->source, $this->clock);
        $this->svc = new OabService($this->pdo, $this->clock);
    }

    private function admin(): \Radar\Tenancy\TenantContext
    {
        return $this->ctx($this->office['user_id'], $this->office['tenant_id']);
    }

    private function addOab(string $number = '12345', string $uf = 'MG', array $supp = []): int
    {
        return (int) $this->svc->add($this->admin(), $number, $uf, 'Dra. Teste', $supp)['id'];
    }

    /** n processos estaduais diferentes em uma UF (tribunal 8.<tr>) */
    private function stateCases(int $n, string $tr, int $year = 2026, int $seqStart = 1): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = F::pub('s' . $tr . ($seqStart + $i), F::cnj($seqStart + $i, $year, 8, $tr), $year . '-03-10');
        }
        return $out;
    }

    // ── Tribunais ────────────────────────────────────────────────────────────

    public function testUfOnlyWhenTribunalCoversOneState(): void
    {
        $this->assertSame('MG', Tribunals::uf('TJMG'));
        $this->assertSame('DF', Tribunals::uf('TJDFT'));
        $this->assertSame('PR', Tribunals::uf('TRT9'));
        $this->assertSame('SP', Tribunals::uf('TRE-SP'));
        $this->assertNull(Tribunals::uf('TRT8'));    // PA e AP
        $this->assertNull(Tribunals::uf('TRF4'));
        $this->assertNull(Tribunals::uf('TRF1'));
        $this->assertNull(Tribunals::uf('STJ'));
        $this->assertNull(Tribunals::uf('TST'));
        $this->assertSame('estadual', Tribunals::segmentGroup(8));
        $this->assertSame('federal', Tribunals::segmentGroup(4));
        $this->assertSame('trabalho', Tribunals::segmentGroup(5));
        $this->assertSame('outros', Tribunals::segmentGroup(3));
    }

    // ── cadastro ─────────────────────────────────────────────────────────────

    public function testAddNormalizesNumberAndSupplementaryUfs(): void
    {
        $oab = $this->svc->add($this->admin(), ' 012.345A ', 'mg', 'Dra. Ana', ['sp', 'MG', 'rj', 'SP']);
        $this->assertSame('12345', $oab['oab_number']);
        $this->assertSame('MG', $oab['oab_uf']);
        $this->assertSame(['RJ', 'SP'], $oab['supplementary_ufs']);
    }

    public function testAddRejectsBadInput(): void
    {
        foreach ([['abc', 'MG'], ['0', 'MG'], ['123', 'XX'], ['12345678', 'MG']] as [$n, $uf]) {
            try {
                $this->svc->add($this->admin(), $n, $uf, 'Dra. Ana');
                $this->fail("aceitou $n/$uf");
            } catch (ValidationError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ValidationError::class);
        $this->svc->add($this->admin(), '1', 'MG', 'Dra. Ana', ['ZZ']);
    }

    public function testDuplicateOabIsRefusedButOtherOfficeMayRegisterSame(): void
    {
        $this->addOab('999', 'SP');
        try {
            $this->addOab('999', 'SP');
            $this->fail('duplicada');
        } catch (ValidationError) {
            $this->addToAssertionCount(1);
        }
        $b = $this->office('B', 'b@b.com');
        $other = $this->svc->add($this->ctx($b['user_id'], $b['tenant_id']), '999', 'SP', 'Dr. B');
        $this->assertSame('999', $other['oab_number']);
    }

    public function testPlanLimitCountsOnlyNonArchived(): void
    {
        $o = $this->office('Peq', 'p@p.com', 'essencial');   // 2 OABs
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $first = (int) $this->svc->add($ctx, '1', 'MG', 'A')['id'];
        $this->svc->add($ctx, '2', 'MG', 'B');
        try {
            $this->svc->add($ctx, '3', 'MG', 'C');
            $this->fail('passou do limite');
        } catch (LimitExceeded) {
            $this->addToAssertionCount(1);
        }
        $this->svc->setStatus($ctx, $first, 'archive');
        $this->assertSame('3', $this->svc->add($ctx, '3', 'MG', 'C')['oab_number']);
    }

    public function testInactiveSubscriptionBlocksNewOab(): void
    {
        $this->pdo->exec("UPDATE subscriptions SET status = 'cancelled'");
        $this->expectException(LimitExceeded::class);
        $this->addOab();
    }

    public function testPermissionsByRole(): void
    {
        $id = $this->addOab();
        $lawyer = $this->ctx($this->member($this->office['tenant_id'], 'l@a.com', 'lawyer'), $this->office['tenant_id']);
        $assistant = $this->ctx($this->member($this->office['tenant_id'], 'x@a.com', 'assistant'), $this->office['tenant_id']);
        $this->assertCount(1, $this->svc->list($lawyer));
        try {
            $this->svc->list($assistant);
            $this->fail('assistente não vê OAB');
        } catch (Forbidden) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(Forbidden::class);
        $this->svc->setStatus($lawyer, $id, 'pause');           // só administrador gerencia
    }

    // ── consulta ─────────────────────────────────────────────────────────────

    public function testFirstCheckIsSilentBaselineSinceJanuaryFirst(): void
    {
        $id = $this->addOab();
        $this->source->then([F::pub('1', F::cnj(1, 2026, 8, '13'), '2026-05-02'), F::pub('2', F::cnj(2, 2026, 5, '03'), '2026-06-02')]);
        $r = $this->monitor->runDue();

        $this->assertSame(['claimed' => 1, 'succeeded' => 1, 'failed' => 0, 'new_publications' => 0], $r);
        $this->assertSame('2026-01-01', $this->source->calls[0]['since']);
        $this->assertSame('2026-10-10', $this->source->calls[0]['until']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM oab_publications WHERE is_baseline = 1'));
        $reg = $this->svc->get($this->admin(), $id);
        $this->assertNotNull($reg['baseline_at']);
        $this->assertNull($reg['last_error']);
    }

    public function testNextCheckOnlyAfterIntervalAndLooksBackThreeDays(): void
    {
        $this->addOab();
        $this->source->then([]);
        $this->monitor->runDue();
        $this->assertSame(0, $this->monitor->runDue()['claimed']);   // ainda não venceu
        $this->clock->advance('PT7H');
        $this->source->then([]);
        $this->assertSame(1, $this->monitor->runDue()['claimed']);
        $this->assertSame('2026-10-07', $this->source->calls[1]['since']);   // 10/10 - 3 dias
    }

    public function testSecondCheckCountsOnlyNewAndDedupes(): void
    {
        $this->addOab();
        $a = F::pub('1', F::cnj(1, 2026, 8, '13'));
        $this->source->then([$a]);
        $this->monitor->runDue();
        $this->clock->advance('PT7H');
        $this->source->then([$a, F::pub('2', F::cnj(2, 2026, 8, '13'))]);
        $r = $this->monitor->runDue();
        $this->assertSame(1, $r['new_publications']);
        $this->clock->advance('PT7H');
        $this->source->then([$a, F::pub('2', F::cnj(2, 2026, 8, '13'))]);
        $this->assertSame(0, $this->monitor->runDue()['new_publications']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM oab_publications'));
    }

    public function testFailureNeverMarksSuccessAndBacksOff(): void
    {
        $id = $this->addOab();
        $this->source->then(new SourceUnavailable('fora do ar'));
        $r = $this->monitor->runDue();
        $this->assertSame(1, $r['failed']);
        $reg = $this->svc->get($this->admin(), $id);
        $this->assertNull($reg['last_success_at']);
        $this->assertNull($reg['baseline_at']);
        $this->assertSame('fora do ar', $reg['last_error']);
        $this->assertSame(1, (int) $reg['fail_count']);
        $this->assertSame(0, $this->monitor->runDue()['claimed']);   // espera o backoff
        $this->clock->advance('PT3M');
        $this->source->then([]);
        $this->assertSame(1, $this->monitor->runDue()['succeeded']);
        $this->assertSame(0, (int) $this->svc->get($this->admin(), $id)['fail_count']);
    }

    public function testUnexpectedErrorIsGenericAndNotLeaked(): void
    {
        $id = $this->addOab();
        $this->source->then(new \RuntimeException('segredo interno'));
        $this->monitor->runDue();
        $this->assertStringNotContainsString('segredo', (string) $this->svc->get($this->admin(), $id)['last_error']);
    }

    public function testPausedArchivedAndInactiveSubscriptionAreNotChecked(): void
    {
        $id = $this->addOab();
        $this->svc->setStatus($this->admin(), $id, 'pause');
        $this->assertSame(0, $this->monitor->runDue()['claimed']);
        $this->svc->setStatus($this->admin(), $id, 'resume');
        $this->pdo->exec("UPDATE subscriptions SET status = 'cancelled'");
        $this->assertSame(0, $this->monitor->runDue()['claimed']);
        $this->pdo->exec("UPDATE subscriptions SET status = 'active'");
        $this->source->then([]);
        $this->assertSame(1, $this->monitor->runDue()['claimed']);
    }

    public function testLeasePreventsDoubleClaimAndConcurrentClaimsSkipLocked(): void
    {
        $this->addOab();
        $this->source->then([]);
        $this->pdo->exec("UPDATE oab_registrations SET locked_until = '2026-10-10 12:10:00'");   // outro processo está com ela
        $this->assertSame(0, $this->monitor->runDue()['claimed']);
        $this->clock->advance('PT20M');   // lease vencido: pode reassumir
        $this->assertSame(1, $this->monitor->runDue()['claimed']);

        // duas conexões ao mesmo tempo: a segunda não espera nem pega a mesma
        $this->pdo->exec("UPDATE oab_registrations SET locked_until = NULL, next_check_at = '2026-01-01 00:00:00'");
        $other = self::connect();
        $this->pdo->beginTransaction();
        $sql = "SELECT r.id FROM oab_registrations r WHERE r.status = 'active' AND r.next_check_at <= NOW() FOR UPDATE SKIP LOCKED";
        $this->assertCount(1, $this->pdo->query($sql)->fetchAll());
        $other->beginTransaction();
        $this->assertCount(0, $other->query($sql)->fetchAll());
        $other->rollBack();
        $this->pdo->rollBack();
    }

    // ── alerta de inscrição suplementar ──────────────────────────────────────

    private function alerts(): array
    {
        return $this->pdo->query('SELECT uf, alert_year, process_count FROM oab_alerts ORDER BY uf')->fetchAll();
    }

    public function testFiveCasesDoNotAlertButSixDo(): void
    {
        $this->addOab('12345', 'MG');
        $this->source->then($this->stateCases(5, '26'));        // SP: 5 processos
        $this->monitor->runDue();
        $this->assertSame([], $this->alerts());
        $this->clock->advance('PT7H');
        $this->source->then($this->stateCases(6, '26'));        // agora 6 (um novo)
        $this->monitor->runDue();
        $this->assertSame([['uf' => 'SP', 'alert_year' => 2026, 'process_count' => 6]], $this->alerts());
    }

    public function testSameCaseManyPublicationsCountsOnce(): void
    {
        $this->addOab();
        $cnj = F::cnj(1, 2026, 8, '26');
        $pubs = [];
        for ($i = 0; $i < 10; $i++) {
            $pubs[] = F::pub("m$i", $cnj, '2026-04-0' . ($i % 9 + 1));
        }
        $this->source->then($pubs);
        $this->monitor->runDue();
        $this->assertSame([], $this->alerts());
    }

    public function testHomeAndSupplementaryUfDoNotAlert(): void
    {
        $this->addOab('12345', 'MG', ['SP']);
        $this->source->then([...$this->stateCases(7, '13'), ...$this->stateCases(7, '26', 2026, 100)]);   // MG (principal) e SP (suplementar)
        $this->monitor->runDue();
        $this->assertSame([], $this->alerts());
    }

    public function testUpdatingSupplementaryUfClearsAlert(): void
    {
        $id = $this->addOab('12345', 'MG');
        $this->source->then($this->stateCases(6, '26'));
        $this->monitor->runDue();
        $this->assertCount(1, $this->alerts());
        $this->svc->update($this->admin(), $id, null, ['SP']);
        $this->assertSame([], $this->alerts());
    }

    public function testUndeterminedUfIsLeftOutOfAlertsAndCountedApart(): void
    {
        $this->addOab('12345', 'MG');
        $pubs = [];
        for ($i = 1; $i <= 8; $i++) {
            $pubs[] = F::pub("t$i", F::cnj($i, 2026, 5, '08'), '2026-02-01');   // TRT8: PA/AP → indeterminada
        }
        $this->source->then($pubs);
        $this->monitor->runDue();
        $this->assertSame([], $this->alerts());
        $s = (new OabStats($this->pdo, $this->clock))->summary($this->admin(), 2026);
        $this->assertSame(8, $s['undetermined']);
        $this->assertSame([], $s['rows']);
    }

    public function testAlertsArePerYear(): void
    {
        $this->addOab();
        $this->source->then([...$this->stateCases(3, '26', 2025), ...$this->stateCases(3, '26', 2026, 50)]);
        $this->monitor->runDue();
        $this->assertSame([], $this->alerts());   // 3 + 3 em anos diferentes não somam
    }

    public function testSummaryByUfGroupAndLawyer(): void
    {
        $this->addOab('1', 'MG');
        $pubs = [...$this->stateCases(2, '26'), F::pub('f1', F::cnj(9, 2026, 4, '06'), '2026-03-01'), F::pub('t1', F::cnj(10, 2026, 5, '02'), '2026-03-01'), F::pub('f2', F::cnj(12, 2026, 4, '03'), '2026-03-01'),
            F::pub('old', F::cnj(11, 2025, 8, '26'), '2025-03-01')];
        $this->source->then($pubs);
        $this->monitor->runDue();
        $s = (new OabStats($this->pdo, $this->clock))->summary($this->admin(), 2026);
        $this->assertSame([2025, 2026], array_reverse($s['years']));
        $byUf = array_column($s['rows'], null, 'uf');
        $this->assertSame(2, $byUf['SP']['groups']['estadual']);
        $this->assertSame(1, $byUf['SP']['groups']['trabalho']);     // TRT2 = SP
        $this->assertSame(1, $byUf['MG']['groups']['federal']);      // TRF6 = MG
        $this->assertSame(1, $s['undetermined']);                    // TRF3 cobre SP e MS: fica de fora
        $this->assertSame(4, $s['totals']['total']);
        $this->assertSame(2, $s['totals']['states']);
        $oabId = array_key_first($byUf['SP']['by_oab']);
        $this->assertSame(3, $byUf['SP']['by_oab'][$oabId]['total']);
        $s2025 = (new OabStats($this->pdo, $this->clock))->summary($this->admin(), 2025);
        $this->assertSame(1, $s2025['totals']['total']);
    }

    public function testMarkSeenAndCount(): void
    {
        $this->addOab();
        $this->source->then($this->stateCases(6, '26'));
        $this->monitor->runDue();
        $stats = new OabStats($this->pdo, $this->clock);
        $this->assertSame(1, $stats->unseenCount($this->admin()));
        $alertId = (int) $this->scalar('SELECT id FROM oab_alerts');
        $this->assertTrue($stats->markSeen($this->admin(), $alertId));
        $this->assertFalse($stats->markSeen($this->admin(), $alertId));
        $this->assertSame(0, $stats->unseenCount($this->admin()));
    }

    // ── publicações, filtros e "monitorar este processo" ─────────────────────

    public function testSearchFiltersAndMonitorPublication(): void
    {
        $this->addOab();
        $sp = F::cnj(1, 2026, 8, '26');
        $this->source->then([F::pub('1', $sp, '2026-03-01', 'Penhora de bens'), F::pub('2', F::cnj(2, 2026, 5, '03'), '2026-04-01', 'Audiência marcada')]);
        $this->monitor->runDue();
        $repo = new OabPublicationRepository($this->pdo, $this->admin());
        $this->assertSame(1, $repo->search(['uf' => 'SP'])['total']);
        $this->assertSame(1, $repo->search(['group' => 'trabalho'])['total']);
        $this->assertSame(1, $repo->search(['q' => 'penhora'])['total']);
        $this->assertSame(1, $repo->search(['q' => preg_replace('/\D/', '', $sp)])['total']);
        $this->assertSame(0, $repo->search(['year' => 2025])['total']);
        $this->assertSame(0, $repo->search(['new_only' => true])['total']);   // tudo é linha de base

        $pubId = (int) $repo->search(['uf' => 'SP'])['items'][0]['id'];
        $this->assertFalse($repo->search(['uf' => 'SP'])['items'][0]['is_monitored']);
        $this->svc->monitorPublication($this->admin(), $pubId);
        $this->assertTrue($repo->search(['uf' => 'SP'])['items'][0]['is_monitored']);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM processes'));
    }

    // ── isolamento entre escritórios ─────────────────────────────────────────

    public function testOfficeBSeesNothingOfOfficeA(): void
    {
        $id = $this->addOab();
        $this->source->then($this->stateCases(6, '26'));
        $this->monitor->runDue();
        $pubId = (int) $this->scalar('SELECT id FROM oab_publications LIMIT 1');
        $alertId = (int) $this->scalar('SELECT id FROM oab_alerts');

        $b = $this->office('B', 'b@b.com', 'profissional');
        $ctxB = $this->ctx($b['user_id'], $b['tenant_id']);
        $this->assertSame([], $this->svc->list($ctxB));
        $this->assertSame(0, (new OabPublicationRepository($this->pdo, $ctxB))->search()['total']);
        $this->assertNull((new OabPublicationRepository($this->pdo, $ctxB))->find($pubId));
        $stats = new OabStats($this->pdo, $this->clock);
        $this->assertSame([], $stats->summary($ctxB, 2026)['rows']);
        $this->assertSame([], $stats->summary($ctxB, 2026)['alerts']);
        $this->assertFalse($stats->markSeen($ctxB, $alertId));
        foreach ([fn () => $this->svc->get($ctxB, $id), fn () => $this->svc->setStatus($ctxB, $id, 'pause'),
                     fn () => $this->svc->update($ctxB, $id, 'X', null), fn () => $this->svc->requestCheck($ctxB, $id),
                     fn () => $this->svc->monitorPublication($ctxB, $pubId)] as $try) {
            try {
                $try();
                $this->fail('vazou entre escritórios');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('active', $this->svc->get($this->admin(), $id)['status']);
    }

    public function testDatabaseRefusesPublicationLinkedToOtherTenantOab(): void
    {
        $id = $this->addOab();
        $b = $this->office('B', 'b@b.com');
        $this->expectException(\PDOException::class);
        $this->pdo->prepare("INSERT INTO oab_publications (tenant_id, oab_id, external_id, cnj, cnj_digits, tribunal, segment, captured_at)
            VALUES (?, ?, 'x', 'c', 'd', 'TJSP', 8, NOW())")->execute([$b['tenant_id'], $id]);
    }
}
