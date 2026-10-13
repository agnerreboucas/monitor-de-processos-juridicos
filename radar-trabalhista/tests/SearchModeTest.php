<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\LimitExceeded;
use Radar\Exception\ValidationError;
use Radar\Monitoring\Worker;
use Radar\Service\BillingService;
use Radar\Service\OabService;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;

final class SearchModeTest extends DbTestCase
{
    private function setup1(): array
    {
        $o = $this->office('Silva', 'a@silva.com', 'profissional');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $pid = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $o['user_id'])['id'];
        return [$o, $ctx, $pid];
    }

    public function testSixHourModeSchedulesTheNextSearchSixHoursAhead(): void
    {
        [$o, $ctx, $pid] = $this->setup1();
        $billing = new BillingService($this->pdo, $this->clock);
        $this->assertSame('daily', $billing->statement($o['tenant_id'])['search_mode']);
        $this->assertSame('six_hours', $billing->saveSettings($ctx, ['search_mode' => 'six_hours'])['search_mode']);
        $worker = new Worker($this->pdo, [(new FakeAdapter('djen'))->then([FakeAdapter::movement('1')])], $this->clock, 'w1');
        $worker->runBatch(10);
        $this->assertSame('2026-10-10 18:00:00', (string) $this->scalar('SELECT next_check_at FROM processes WHERE id = ?', [$pid]));   // 12:00 + 6 h, mesmo no plano de 1 consulta por dia
        $billing->saveSettings($ctx, ['search_mode' => 'daily']);
        $this->clock->advance('PT7H');
        $worker->enqueueDue();
        $worker2 = new Worker($this->pdo, [(new FakeAdapter('djen'))->then([])], $this->clock, 'w2');
        $worker2->runBatch(10);
        $this->assertSame('2026-10-11 19:00:00', (string) $this->scalar('SELECT next_check_at FROM processes WHERE id = ?', [$pid]));   // de volta a 1 por dia
    }

    public function testChangingToSixHoursPullsLaterSearchesCloser(): void
    {
        [$o, $ctx, $pid] = $this->setup1();
        $this->pdo->prepare("UPDATE processes SET next_check_at = '2026-10-12 08:00:00' WHERE id = ?")->execute([$pid]);
        (new BillingService($this->pdo, $this->clock))->saveSettings($ctx, ['search_mode' => 'six_hours']);
        $this->assertSame('2026-10-10 18:00:00', (string) $this->scalar('SELECT next_check_at FROM processes WHERE id = ?', [$pid]));
    }

    public function testInvalidModeIsRefused(): void
    {
        [$o, $ctx] = $this->setup1();
        $this->expectException(ValidationError::class);
        (new BillingService($this->pdo, $this->clock))->saveSettings($ctx, ['search_mode' => 'a-cada-minuto']);
    }

    public function testCheckAllQueuesEverythingOnceAndLimitsRepeatsToEveryFifteenMinutes(): void
    {
        [$o, $ctx, $pid] = $this->setup1();
        $svc = new ProcessService($this->pdo, $this->clock);
        $svc->create($ctx, '0003320-79.2025.5.02.0041', 'Outro', $o['user_id']);
        (new OabService($this->pdo, $this->clock))->add($ctx, '123', 'MG', 'Dr. A');
        $this->pdo->exec('DELETE FROM monitoring_jobs');
        $r = $svc->requestCheckAll($ctx);
        $this->assertSame([2, 1], [$r['processes'], $r['oabs']]);
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_jobs WHERE status = 'queued'"));
        try {
            $svc->requestCheckAll($ctx);
            $this->fail('deixou repetir');
        } catch (LimitExceeded) {
            $this->addToAssertionCount(1);
        }
        $this->clock->advance('PT16M');
        $this->assertSame(0, $svc->requestCheckAll($ctx)['processes']);     // já estão na fila: não duplica
    }

    public function testSingleCheckHasFiveMinuteGapAndAssistantsCannotCheckAll(): void
    {
        [$o, $ctx, $pid] = $this->setup1();
        (new Worker($this->pdo, [(new FakeAdapter('djen'))->then([])], $this->clock, 'w1'))->runBatch(10);
        $svc = new ProcessService($this->pdo, $this->clock);
        try {
            $svc->requestCheck($ctx, $pid);
            $this->fail('consultou de novo na hora');
        } catch (LimitExceeded) {
            $this->addToAssertionCount(1);
        }
        $this->clock->advance('PT6M');
        $this->assertTrue($svc->requestCheck($ctx, $pid));
        $assistant = $this->ctx($this->member($o['tenant_id'], 'ass@silva.com', 'assistant'), $o['tenant_id']);
        $this->expectException(Forbidden::class);
        $svc->requestCheckAll($assistant);
    }
}
