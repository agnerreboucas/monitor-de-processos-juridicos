<?php
declare(strict_types=1);

namespace Radar\Tests;

use PDO;
use Radar\Monitoring\InvalidResponse;
use Radar\Monitoring\SourceUnavailable;
use Radar\Monitoring\Worker;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;

final class WorkerTest extends DbTestCase
{
    private array $office;
    private int $processId;
    private FakeAdapter $adapter;
    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = $this->office('A', 'a@a.com');
        $this->adapter = new FakeAdapter();
        $this->worker = new Worker($this->pdo, [$this->adapter], $this->clock, 'w1');
        $ctx = $this->ctx($this->office['user_id'], $this->office['tenant_id']);
        $this->processId = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $this->office['user_id'])['id'];
    }

    private function process(): array
    {
        return $this->pdo->query('SELECT * FROM processes WHERE id = ' . $this->processId)->fetch();
    }

    private function movements(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM process_movements WHERE process_id = ?', [$this->processId]);
    }

    private function notifications(string $channel = 'panel'): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE process_id = ? AND channel = ?', [$this->processId, $channel]);
    }

    // ── primeira consulta: histórico em silêncio ─────────────────────────────

    public function testFirstSuccessfulCheckImportsHistoryWithoutAlerts(): void
    {
        $this->adapter->then([FakeAdapter::movement('1'), FakeAdapter::movement('2'), FakeAdapter::movement('3')]);
        $r = $this->worker->runBatch(10);

        $this->assertSame(1, $r['succeeded']);
        $this->assertSame(0, $r['new_movements']);                            // histórico não é novidade
        $this->assertSame(3, $this->movements());
        $this->assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM process_movements WHERE is_baseline = 1'));
        $this->assertSame(0, $this->notifications());
        $p = $this->process();
        $this->assertNotNull($p['baseline_at']);
        $this->assertNotNull($p['last_success_at']);
        $this->assertNull($p['last_error']);
        $this->assertStringContainsString('Primeira consulta: 3', (string) $this->scalar('SELECT message FROM monitoring_events'));
        $this->assertNull($this->adapter->calls[0]['since']);                  // histórico completo na primeira vez
    }

    // ── movimentação nova ────────────────────────────────────────────────────

    private function firstCheckThenNextCycle(array $baseline): void
    {
        $this->adapter->then($baseline);
        $this->worker->runBatch(10);
        $this->clock->advance('PT7H');                                         // passou o intervalo do plano (6h)
        $this->assertSame(1, $this->worker->enqueueDue());
    }

    public function testNewMovementCreatesOneAlertPerChannelForTheResponsible(): void
    {
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $this->adapter->then([FakeAdapter::movement('1'), FakeAdapter::movement('2', 'Audiência designada')]);
        $r = $this->worker->runBatch(10);

        $this->assertSame(1, $r['new_movements']);
        $this->assertSame(2, $this->movements());
        $this->assertSame(1, $this->notifications('panel'));
        $this->assertSame(1, $this->notifications('email'));
        $this->assertSame('new_movements', $this->scalar('SELECT outcome FROM monitoring_events ORDER BY id DESC LIMIT 1'));
        $this->assertSame(0, (int) $this->scalar('SELECT is_baseline FROM process_movements WHERE external_id = ?', ['djen-2']));
        // as duas datas ficam separadas: a da movimentação e a da captura
        $row = $this->pdo->query("SELECT occurred_at, captured_at FROM process_movements WHERE external_id = 'djen-2'")->fetch();
        $this->assertSame('2026-10-09', $row['occurred_at']);
        $this->assertSame('2026-10-10 19:00:00', $row['captured_at']);
    }

    public function testSubsequentCheckAsksOnlyFromThreeDaysBeforeTheLastSuccess(): void
    {
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $this->adapter->then([]);
        $this->worker->runBatch(10);
        $this->assertSame('2026-10-07 12:00:00', $this->adapter->calls[1]['since']);   // 10/10 12:00 menos 3 dias
    }

    public function testReprocessingTheSameResultNeverDuplicatesMovementsOrAlerts(): void
    {
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $same = [FakeAdapter::movement('1'), FakeAdapter::movement('2')];
        $this->adapter->then($same);
        $this->worker->runBatch(10);
        for ($i = 0; $i < 3; $i++) {                                           // o diário devolve o mesmo de novo e de novo
            $this->clock->advance('PT7H');
            $this->worker->enqueueDue();
            $this->adapter->then($same);
            $r = $this->worker->runBatch(10);
            $this->assertSame(0, $r['new_movements']);
        }
        $this->assertSame(2, $this->movements());
        $this->assertSame(1, $this->notifications('panel'));
        $this->assertSame(1, $this->notifications('email'));
    }

    public function testNoNewsIsRecordedAsNoNews(): void
    {
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $this->adapter->then([FakeAdapter::movement('1')]);
        $this->worker->runBatch(10);
        $this->assertSame('no_news', $this->scalar('SELECT outcome FROM monitoring_events ORDER BY id DESC LIMIT 1'));
        $this->assertSame(0, $this->notifications());
    }

    public function testNextCheckFollowsThePlanInterval(): void
    {
        $this->adapter->then([]);
        $this->worker->runBatch(10);
        $this->assertSame('2026-10-10 18:00:00', $this->process()['next_check_at']);     // essencial: 360 min
    }

    // ── falha NÃO é "sem novidade" ───────────────────────────────────────────

    public function testSourceFailureIsNeverTreatedAsNoNewsAndNeverMarksTheProcessUpdated(): void
    {
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $lastSuccess = $this->process()['last_success_at'];
        $this->adapter->then(new SourceUnavailable('DJEN indisponível (HTTP 503).'));
        $r = $this->worker->runBatch(10);

        $this->assertSame(1, $r['failed']);
        $p = $this->process();
        $this->assertSame($lastSuccess, $p['last_success_at']);                 // continua a data da última consulta que DEU CERTO
        $this->assertNotSame($p['last_success_at'], $p['last_attempt_at']);     // a tentativa é registrada à parte
        $this->assertStringContainsString('503', (string) $p['last_error']);
        $this->assertSame('source_error', $this->scalar('SELECT outcome FROM monitoring_events ORDER BY id DESC LIMIT 1'));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_events WHERE outcome = 'no_news' AND message LIKE '%503%'"));
    }

    public function testFailureIsRetriedWithGrowingWaitAndGivesUpAfterTheLimit(): void
    {
        $this->adapter->then(new SourceUnavailable('caiu'))->then(new SourceUnavailable('caiu'))->then(new SourceUnavailable('caiu'))
            ->then(new SourceUnavailable('caiu'))->then(new SourceUnavailable('caiu'));
        $waits = [];
        for ($i = 1; $i <= 5; $i++) {
            $this->worker->runBatch(10);
            $job = $this->pdo->query('SELECT status, attempts, run_after FROM monitoring_jobs')->fetch();
            $waits[] = (strtotime($job['run_after'] . ' UTC') - $this->clock->now()->getTimestamp()) / 60;
            if ($job['status'] === 'failed') {
                break;
            }
            $this->assertSame('queued', $job['status']);
            $this->assertSame(0, $this->worker->runBatch(10)['claimed']);        // ainda não é a hora de tentar de novo
            $this->clock->advance('PT' . (int) $waits[count($waits) - 1] . 'M');
        }
        $this->assertEquals([2, 4, 8, 16], array_slice($waits, 0, 4));            // 2, 4, 8, 16 minutos
        $this->assertSame('failed', $this->scalar('SELECT status FROM monitoring_jobs'));
        $this->assertSame(5, (int) $this->scalar('SELECT attempts FROM monitoring_jobs'));
        $this->assertSame(5, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_events WHERE outcome = 'source_error'"));
        $this->assertNull($this->process()['last_success_at']);                  // nunca deu certo, e a tela sabe disso
        $this->assertNotNull($this->process()['last_error']);
    }

    public function testRecoversAfterTheSourceComesBack(): void
    {
        $this->adapter->then(new SourceUnavailable('caiu'))->then([FakeAdapter::movement('1')]);
        $this->worker->runBatch(10);
        $this->clock->advance('PT2M');
        $r = $this->worker->runBatch(10);
        $this->assertSame(1, $r['succeeded']);
        $this->assertNull($this->process()['last_error']);                       // erro limpo ao dar certo
        $this->assertNotNull($this->process()['last_success_at']);
    }

    public function testInvalidResponseIsRecordedAsSuch(): void
    {
        $this->adapter->then(new InvalidResponse('O DJEN devolveu algo que não é JSON.'));
        $this->worker->runBatch(10);
        $this->assertSame('invalid_response', $this->scalar('SELECT outcome FROM monitoring_events'));
        $this->assertSame(0, $this->movements());
    }

    public function testUnexpectedExceptionDoesNotBreakTheBatchOrLeakDetails(): void
    {
        $this->adapter->then(new \RuntimeException('segredo: senha=123 em /var/www/x'));
        $r = $this->worker->runBatch(10);
        $this->assertSame(1, $r['failed']);
        $this->assertStringNotContainsString('senha=123', (string) $this->process()['last_error']);
    }

    // ── fila, trava e concorrência ───────────────────────────────────────────

    public function testTwoWorkersNeverTakeTheSameJob(): void
    {
        $ctx = $this->ctx($this->office['user_id'], $this->office['tenant_id']);
        (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TJMG);           // segundo trabalho na fila
        $other = self::connect();                                                               // outra conexão = outro worker

        // o "outro worker" reserva o primeiro trabalho com a mesma consulta que o worker real usa
        $other->beginTransaction();
        $claim = $other->prepare("SELECT id FROM monitoring_jobs WHERE status = 'queued' AND run_after <= ? ORDER BY run_after ASC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $claim->execute(['2026-10-10 12:00:00']);
        $locked = $claim->fetchColumn();
        $this->assertNotFalse($locked);
        $this->adapter->then([])->then([]);
        $r = $this->worker->runBatch(10);          // o primeiro trabalho está travado pelo outro worker: este pega só o segundo
        $other->rollBack();

        $this->assertSame(1, $r['claimed']);
        $this->assertSame('queued', $this->scalar('SELECT status FROM monitoring_jobs WHERE id = ?', [$locked]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_jobs WHERE status = 'succeeded'"));
    }

    public function testDatabaseItselfRefusesDuplicateMovements(): void
    {
        $this->adapter->then([FakeAdapter::movement('1')]);
        $this->worker->runBatch(10);
        $this->expectException(\PDOException::class);
        $this->pdo->prepare("INSERT INTO process_movements (tenant_id, process_id, source_id, external_id, captured_at, description)
                             VALUES (?, ?, 1, 'djen-1', '2026-10-10 12:00:00', 'duplicada')")
            ->execute([$this->office['tenant_id'], $this->processId]);
    }

    public function testStuckJobsAreRecovered(): void
    {
        $this->pdo->exec("UPDATE monitoring_jobs SET status = 'running', attempts = 1, locked_at = '2026-10-10 11:00:00', locked_by = 'morto'");
        $this->assertSame(1, $this->worker->recoverStale());                     // preso há 1h > 15 min
        $this->assertSame('queued', $this->scalar('SELECT status FROM monitoring_jobs'));
        $this->pdo->exec("UPDATE monitoring_jobs SET status = 'running', attempts = 5, locked_at = '2026-10-10 11:00:00'");
        $this->worker->recoverStale();
        $this->assertSame('failed', $this->scalar('SELECT status FROM monitoring_jobs'));
    }

    public function testRecentRunningJobIsNotStolen(): void
    {
        $this->pdo->exec("UPDATE monitoring_jobs SET status = 'running', attempts = 1, locked_at = '2026-10-10 11:55:00', locked_by = 'vivo'");
        $this->assertSame(0, $this->worker->recoverStale());
    }

    public function testSchedulerQueuesDueProcessesOnlyOnce(): void
    {
        $this->adapter->then([]);
        $this->worker->runBatch(10);
        $this->assertSame(0, $this->worker->enqueueDue());                       // ainda não é a hora
        $this->clock->advance('PT7H');
        $this->assertSame(1, $this->worker->enqueueDue());
        $this->assertSame(0, $this->worker->enqueueDue());                       // já tem trabalho na fila: não duplica
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_jobs WHERE status = 'queued'"));
    }

    public function testPausedProcessAndInactiveSubscriptionAreNeverChecked(): void
    {
        $this->pdo->exec("UPDATE processes SET status = 'paused'");
        $r = $this->worker->runBatch(10);
        $this->assertSame(1, $r['cancelled']);
        $this->assertSame([], $this->adapter->calls);

        $this->pdo->exec("UPDATE processes SET status = 'active', next_check_at = '2026-10-10 11:00:00'");
        $this->pdo->exec("UPDATE subscriptions SET status = 'suspended'");
        $this->assertSame(0, $this->worker->enqueueDue());                       // nem agenda
        $this->pdo->exec("UPDATE subscriptions SET status = 'active'");
        $this->pdo->exec("UPDATE tenants SET status = 'suspended'");
        $this->assertSame(0, $this->worker->enqueueDue());
        $this->assertSame([], $this->adapter->calls);
    }

    public function testQueuedJobOfASuspendedSubscriptionIsCancelledNotRun(): void
    {
        $this->pdo->exec("UPDATE subscriptions SET status = 'suspended'");
        $r = $this->worker->runBatch(10);
        $this->assertSame(1, $r['cancelled']);
        $this->assertSame([], $this->adapter->calls);
        $this->assertSame('cancelled', $this->scalar('SELECT status FROM monitoring_jobs'));
    }

    // ── quem recebe o alerta ─────────────────────────────────────────────────

    public function testWithoutResponsibleAdminsAndLawyersAreNotifiedButNotAssistants(): void
    {
        $this->pdo->exec('UPDATE processes SET responsible_user_id = NULL');
        $lawyer = $this->member($this->office['tenant_id'], 'adv@a.com', 'lawyer');
        $assistant = $this->member($this->office['tenant_id'], 'ass@a.com', 'assistant');
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $this->adapter->then([FakeAdapter::movement('1'), FakeAdapter::movement('2')]);
        $this->worker->runBatch(10);

        $users = array_map('intval', $this->pdo->query("SELECT user_id FROM notifications WHERE channel = 'panel'")->fetchAll(PDO::FETCH_COLUMN));
        sort($users);
        $expected = [$this->office['user_id'], $lawyer];
        sort($expected);
        $this->assertSame($expected, $users);
        $this->assertNotContains($assistant, $users);
    }

    public function testWhenTheResponsibleLeftTheOfficeAdminsAreNotified(): void
    {
        $gone = $this->member($this->office['tenant_id'], 'saiu@a.com', 'lawyer');
        $this->pdo->exec('UPDATE processes SET responsible_user_id = ' . $gone);
        $this->pdo->exec("UPDATE tenant_members SET status = 'disabled' WHERE user_id = " . $gone);
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $this->adapter->then([FakeAdapter::movement('1'), FakeAdapter::movement('2')]);
        $this->worker->runBatch(10);
        $this->assertSame($this->office['user_id'], (int) $this->scalar("SELECT user_id FROM notifications WHERE channel = 'panel'"));
    }

    public function testAlertsGoOnlyToTheResponsibleWhenThereIsOne(): void
    {
        $this->member($this->office['tenant_id'], 'outro@a.com', 'lawyer');
        $this->firstCheckThenNextCycle([FakeAdapter::movement('1')]);
        $this->adapter->then([FakeAdapter::movement('1'), FakeAdapter::movement('2')]);
        $this->worker->runBatch(10);
        $this->assertSame(1, $this->notifications('panel'));
        $this->assertSame($this->office['user_id'], (int) $this->scalar("SELECT user_id FROM notifications WHERE channel = 'panel'"));
    }

    public function testBackoffIsCappedAtSixHours(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 00:00:00');
        $minutes = static fn (int $attempts): int => (int) (($base->add(Worker::backoff($attempts))->getTimestamp() - $base->getTimestamp()) / 60);
        $this->assertSame(2, $minutes(1));
        $this->assertSame(16, $minutes(4));
        $this->assertSame(360, $minutes(9));        // 2^9 = 512 min, limitado a 6 h
        $this->assertSame(360, $minutes(60));       // sem estourar com muitas tentativas
    }
}
