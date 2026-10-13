<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Monitoring\SourceUnavailable;
use Radar\Monitoring\Worker;
use Radar\Service\BillingService;
use Radar\Service\ProcessService;
use Radar\Support\Schedule;
use Radar\Tests\Support\FakeAdapter;

final class BillingScheduleTest extends DbTestCase
{
    public function testScheduleNormalizesAndFindsTheNextRunInBrasiliaTime(): void
    {
        $this->assertSame('05:30:00', Schedule::normalize('5:30'));
        $this->assertSame('05:30:00', Schedule::normalize('05:30:59'));
        $this->assertNull(Schedule::normalize(''));
        $this->assertFalse(Schedule::normalize('25:00'));
        $this->assertFalse(Schedule::normalize('abc'));
        // 10/10 12:00 UTC = 09:00 em Brasília: 05:30 de Brasília é amanhã (11/10 08:30 UTC)
        $this->assertSame('2026-10-11 08:30:00', Schedule::nextRun($this->clock->now(), '05:30:00')->format('Y-m-d H:i:s'));
        // 10:00 de Brasília ainda é hoje (13:00 UTC)
        $this->assertSame('2026-10-10 13:00:00', Schedule::nextRun($this->clock->now(), '10:00:00')->format('Y-m-d H:i:s'));
    }

    private function officeWithProcess(string $plan = 'por-busca'): array
    {
        $o = $this->office('Silva', 'a@silva.com', $plan);
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $pid = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $o['user_id'])['id'];
        return [$o, $ctx, $pid];
    }

    public function testWorkerSchedulesTheNextSearchAtTheChosenTimeAndCountsOnlySuccesses(): void
    {
        [$o, $ctx, $pid] = $this->officeWithProcess();
        (new BillingService($this->pdo, $this->clock))->saveSettings($ctx, ['search_time' => '05:30']);
        $djen = (new FakeAdapter('djen'))->then([FakeAdapter::movement('1')])->then(new SourceUnavailable('DJEN fora do ar.'))->then(new SourceUnavailable('de novo'));
        $worker = new Worker($this->pdo, [$djen], $this->clock, 'w1');
        $this->assertSame(1, $worker->runBatch(10)['succeeded']);
        $next = (string) $this->scalar('SELECT next_check_at FROM processes WHERE id = ?', [$pid]);
        $this->assertSame('2026-10-11 08:30:00', $next);                                    // próxima: 05:30 de Brasília, não daqui a X horas
        $b = new BillingService($this->pdo, $this->clock);
        $s = $b->statement($o['tenant_id']);
        $this->assertSame([1, 0], [$s['ok'], $s['failed']]);

        $this->clock->advance('PT21H');                                                    // chegou a hora
        $this->assertSame(1, $worker->enqueueDue());
        $worker->runBatch(10);                                                             // falhou
        $s = $b->statement($o['tenant_id']);
        $this->assertSame([1, 1], [$s['ok'], $s['failed']]);                               // a falha aparece, mas não conta como busca cobrada
    }

    public function testStatementChargesPerSuccessfulSearchAndTrialIsFree(): void
    {
        [$o] = $this->officeWithProcess();
        $b = new BillingService($this->pdo, $this->clock);
        for ($i = 0; $i < 10; $i++) {
            $b->record($o['tenant_id'], 'process', true);
        }
        $b->record($o['tenant_id'], 'process', false);
        $b->record($o['tenant_id'], 'lookup', true);
        $s = $b->statement($o['tenant_id']);
        $this->assertSame([11, 1, 30], [$s['ok'], $s['failed'], $s['price_per_search_cents']]);
        $this->assertTrue($s['trial']);
        $this->assertSame(0, $s['amount_cents']);                                          // teste grátis: nada a cobrar
        $this->assertSame(330, $s['would_be_cents']);                                      // mas mostra quanto seria: 11 × R$ 0,30
        $this->pdo->exec("UPDATE subscriptions SET status = 'active'");
        $this->assertSame(330, $b->statement($o['tenant_id'])['amount_cents']);            // as 11 que deram certo; a que falhou fica de fora
    }

    public function testSpendCapPausesSearchesAndOnlyTheOfficeAdminChangesSettings(): void
    {
        [$o, $ctx, $pid] = $this->officeWithProcess();
        $this->pdo->exec("UPDATE subscriptions SET status = 'active'");
        $b = new BillingService($this->pdo, $this->clock);
        $b->saveSettings($ctx, ['spend_cap_cents' => 300]);                                // R$ 3,00 = 10 buscas
        for ($i = 0; $i < 10; $i++) {
            $b->record($o['tenant_id'], 'process', true);
        }
        $this->assertTrue($b->capReached($o['tenant_id']));
        $worker = new Worker($this->pdo, [(new FakeAdapter('djen'))->then([FakeAdapter::movement('1')])], $this->clock, 'w1');
        $r = $worker->runBatch(10);
        $this->assertSame([0, 1], [$r['succeeded'], $r['cancelled']]);                     // não gasta além do teto
        $b->saveSettings($ctx, ['spend_cap_cents' => null]);
        $this->assertFalse($b->capReached($o['tenant_id']));

        $assistant = $this->member($o['tenant_id'], 'ass@silva.com', 'assistant');
        $this->expectException(Forbidden::class);
        $b->saveSettings($this->ctx($assistant, $o['tenant_id']), ['search_time' => '06:00']);
    }

    public function testSettingATimeMovesTheAlreadyScheduledSearchesAndValidates(): void
    {
        [$o, $ctx, $pid] = $this->officeWithProcess();
        $this->pdo->exec("UPDATE processes SET next_check_at = '2026-10-10 18:00:00' WHERE id = $pid");
        $b = new BillingService($this->pdo, $this->clock);
        $b->saveSettings($ctx, ['search_time' => '05:30']);
        $this->assertSame('2026-10-11 08:30:00', (string) $this->scalar('SELECT next_check_at FROM processes WHERE id = ?', [$pid]));
        $this->assertSame('05:30', $b->statement($o['tenant_id'])['search_time']);
        $this->expectException(ValidationError::class);
        $b->saveSettings($ctx, ['search_time' => '99:99']);
    }

    public function testOwnerCsvListsEachOfficeWithoutChargingFailures(): void
    {
        [$o] = $this->officeWithProcess();
        $owner = $this->office('Plataforma', 'dono@saas.com');
        $this->pdo->exec('UPDATE users SET is_platform_admin = 1 WHERE id = ' . $owner['user_id']);
        $this->pdo->exec("UPDATE subscriptions SET status = 'active'");
        $b = new BillingService($this->pdo, $this->clock);
        $b->record($o['tenant_id'], 'process', true);
        $b->record($o['tenant_id'], 'process', true);
        $b->record($o['tenant_id'], 'process', false);
        $csv = $b->csvForOwner($owner['user_id'], null);
        $this->assertMatchesRegularExpression('/Silva;"Pague por busca";"por busca";não;0,30;2;1;0,60/', $csv);
        $this->expectException(Forbidden::class);
        $b->csvForOwner($o['user_id'], null);
    }
}
