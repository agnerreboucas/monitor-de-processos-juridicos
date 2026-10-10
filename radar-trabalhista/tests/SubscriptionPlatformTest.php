<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Monitoring\Worker;
use Radar\Service\OabService;
use Radar\Service\PlatformService;
use Radar\Service\ProcessService;
use Radar\Service\SubscriptionService;
use Radar\Tests\Support\FakeAdapter;

final class SubscriptionPlatformTest extends DbTestCase
{
    private SubscriptionService $subs;
    private PlatformService $platform;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subs = new SubscriptionService($this->pdo, $this->clock);
        $this->platform = new PlatformService($this->pdo, $this->clock);
        $this->adminId = $this->makePlatformAdmin('dono@radar.com');
    }

    private function makePlatformAdmin(string $email): int
    {
        $o = $this->office('Dono', $email);
        $this->pdo->prepare('UPDATE users SET is_platform_admin = 1 WHERE id = ?')->execute([$o['user_id']]);
        return $o['user_id'];
    }

    private function sub(int $tenantId): array
    {
        return $this->pdo->query('SELECT * FROM subscriptions WHERE tenant_id = ' . $tenantId)->fetch();
    }

    // ── ciclo da assinatura ──────────────────────────────────────────────────

    public function testRegistrationStartsA14DayTrial(): void
    {
        $o = $this->office('A', 'a@a.com');
        $s = $this->sub($o['tenant_id']);
        $this->assertSame('trialing', $s['status']);
        $this->assertSame('2026-10-24 12:00:00', $s['current_period_end']);
    }

    public function testTrialExpiresToPastDueThenSuspendedAfterGrace(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->clock->advance('P13D');
        $this->assertSame(['past_due' => 0, 'suspended' => 0], $this->subs->tick());
        $this->clock->advance('P2D');                                       // venceu ontem (também vence o teste do escritório do dono)
        $this->assertSame(['past_due' => 2, 'suspended' => 0], $this->subs->tick());
        $this->assertSame('past_due', $this->sub($o['tenant_id'])['status']);
        $this->assertSame(['past_due' => 0, 'suspended' => 0], $this->subs->tick());   // repetir não muda nada
        $this->clock->advance('P3D');
        $this->assertSame(['past_due' => 0, 'suspended' => 2], $this->subs->tick());
        $s = $this->sub($o['tenant_id']);
        $this->assertSame('suspended', $s['status']);
        $this->assertNotNull($s['suspended_reason']);
    }

    public function testSubscriptionsWithoutEndDateNeverExpire(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->pdo->exec('UPDATE subscriptions SET current_period_end = NULL');
        $this->clock->advance('P400D');
        $this->assertSame(['past_due' => 0, 'suspended' => 0], $this->subs->tick());
    }

    public function testRenewActivatesAndAddsDaysFromTheLaterOfNowOrCurrentEnd(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->subs->renew($o['tenant_id'], 30);                              // ainda no teste: soma ao fim atual (24/10)
        $this->assertSame('active', $this->sub($o['tenant_id'])['status']);
        $this->assertSame('2026-11-23 12:00:00', $this->sub($o['tenant_id'])['current_period_end']);
        $this->clock->advance('P60D');
        $this->subs->tick();
        $this->subs->tick();
        $this->subs->renew($o['tenant_id'], 30);                              // vencida: conta a partir de hoje
        $this->assertSame('2027-01-08 12:00:00', $this->sub($o['tenant_id'])['current_period_end']);
        $this->assertNull($this->sub($o['tenant_id'])['suspended_reason']);
        $this->expectException(ValidationError::class);
        $this->subs->renew($o['tenant_id'], 0);
    }

    public function testSuspendedSubscriptionStopsMonitoringAndRenewResumesIt(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'X', $o['user_id']);
        $adapter = new FakeAdapter();
        $worker = new Worker($this->pdo, [$adapter], $this->clock, 'w');
        $this->clock->advance('P20D');
        $this->subs->tick();
        $this->clock->advance('P4D');
        $this->subs->tick();
        $this->assertSame('suspended', $this->sub($o['tenant_id'])['status']);
        $worker->enqueueDue();
        $adapter->then([FakeAdapter::movement('1')]);
        $r = $worker->runBatch(10);
        $this->assertSame(0, $r['succeeded']);                                // suspenso: não consulta
        $this->assertSame([], $adapter->calls);
        $this->subs->renew($o['tenant_id'], 30);
        $worker->enqueueDue();
        $this->assertSame(1, $worker->runBatch(10)['succeeded']);             // renovado: volta a consultar
        $this->assertCount(1, $adapter->calls);
    }

    public function testBannerMessages(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->assertSame('info', $this->subs->banner('trialing', '2026-10-24 12:00:00')['level']);
        $this->assertSame('amarelo', $this->subs->banner('trialing', '2026-10-12 12:00:00')['level']);
        $this->assertSame('amarelo', $this->subs->banner('past_due', '2026-10-01 12:00:00')['level']);
        $this->assertSame('erro', $this->subs->banner('suspended', '2026-10-01 12:00:00')['level']);
        $this->assertSame('', $this->subs->banner('active', '2027-01-01 00:00:00')['message']);
    }

    public function testChangingPlanDownIsRefusedWhileOverTheNewLimit(): void
    {
        $o = $this->office('A', 'a@a.com', 'profissional');                    // 5 OABs
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $oabs = new OabService($this->pdo, $this->clock);
        for ($i = 1; $i <= 3; $i++) {
            $oabs->add($ctx, (string) $i, 'MG', "Adv $i");
        }
        try {
            $this->subs->setPlan($o['tenant_id'], 'essencial');                 // essencial: 2 OABs
            $this->fail('deixou reduzir acima do uso');
        } catch (ValidationError) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('profissional', $this->pdo->query('SELECT p.code FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = ' . $o['tenant_id'])->fetchColumn());
        $this->subs->setPlan($o['tenant_id'], 'equipe');
        $this->assertSame('equipe', $this->pdo->query('SELECT p.code FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = ' . $o['tenant_id'])->fetchColumn());
    }

    // ── administração da plataforma ──────────────────────────────────────────

    public function testOnlyPlatformAdminsPassAndTheCheckIsInTheDatabase(): void
    {
        $normal = $this->office('A', 'a@a.com');
        foreach ([fn () => $this->platform->overview($normal['user_id']), fn () => $this->platform->tenants($normal['user_id']),
                     fn () => $this->platform->renew($normal['user_id'], $normal['tenant_id'], 30),
                     fn () => $this->platform->setTenantStatus($normal['user_id'], $normal['tenant_id'], 'suspended'),
                     fn () => $this->platform->tenant($normal['user_id'], $normal['tenant_id'])] as $try) {
            try {
                $try();
                $this->fail('usuário comum entrou na administração');
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
        $this->pdo->exec("UPDATE users SET status = 'disabled' WHERE id = " . $this->adminId);   // admin desativado perde o acesso
        $this->expectException(Forbidden::class);
        $this->platform->overview($this->adminId);
    }

    public function testOverviewCountsEverythingAndNeverExposesContent(): void
    {
        $a = $this->office('A', 'a@a.com', 'profissional');
        $this->subs->renew($a['tenant_id'], 30);
        (new ProcessService($this->pdo, $this->clock))->create($this->ctx($a['user_id'], $a['tenant_id']), self::CNJ_TRT8, 'Cliente Secreto', $a['user_id']);
        $o = $this->platform->overview($this->adminId);
        $this->assertSame(2, $o['tenants']);
        $this->assertSame(1, $o['processes_active']);
        $this->assertSame(1, $o['subscriptions']['active']);
        $this->assertSame(1, $o['subscriptions']['trialing']);                 // o escritório do próprio dono
        $price = (int) $this->pdo->query("SELECT price_cents FROM plans WHERE code = 'profissional'")->fetchColumn();
        $this->assertSame($price, $o['monthly_revenue_cents']);
        $this->assertTrue($o['worker_stale']);                                 // nenhuma rodada registrada
        $this->assertStringNotContainsString('Cliente Secreto', json_encode($this->platform->tenants($this->adminId)));
        $this->assertStringNotContainsString(self::CNJ_TRT8, json_encode($this->platform->tenant($this->adminId, $a['tenant_id'])));
    }

    public function testWorkerHeartbeatClearsTheStaleFlag(): void
    {
        $this->pdo->prepare('INSERT INTO worker_runs (started_at, finished_at, summary) VALUES (?, ?, ?)')->execute(['2026-10-10 11:55:00', '2026-10-10 11:55:20', '{}']);
        $this->assertFalse($this->platform->overview($this->adminId)['worker_stale']);
        $this->clock->advance('PT30M');
        $this->assertTrue($this->platform->overview($this->adminId)['worker_stale']);
    }

    public function testTenantListFiltersSearchesAndShowsUsage(): void
    {
        $a = $this->office('Alfa Advocacia', 'alfa@a.com', 'profissional');
        $b = $this->office('Beta Trabalhista', 'beta@b.com');
        $this->subs->renew($a['tenant_id'], 30);
        (new ProcessService($this->pdo, $this->clock))->create($this->ctx($a['user_id'], $a['tenant_id']), self::CNJ_TRT8, null, $a['user_id']);
        $this->assertSame(3, $this->platform->tenants($this->adminId)['total']);
        $r = $this->platform->tenants($this->adminId, 'alfa');
        $this->assertSame(1, $r['total']);
        $this->assertSame(1, (int) $r['items'][0]['processes']);
        $this->assertSame('profissional', $r['items'][0]['plan_code']);
        $this->assertSame(1, $this->platform->tenants($this->adminId, 'beta@b.com')['total']);   // acha pelo e-mail de um integrante
        $this->assertSame(1, $this->platform->tenants($this->adminId, '', 'active')['total']);
        $this->assertSame(0, $this->platform->tenants($this->adminId, '%')['total']);              // curinga é tratado como texto
    }

    public function testAdminActionsChangeStateAndAreAuditedInTheAffectedOffice(): void
    {
        $a = $this->office('A', 'a@a.com');
        $this->platform->setPlan($this->adminId, $a['tenant_id'], 'equipe', '9.9.9.9');
        $this->platform->renew($this->adminId, $a['tenant_id'], 30);
        $this->platform->setSubscriptionStatus($this->adminId, $a['tenant_id'], 'suspended', 'Inadimplência');
        $this->platform->setNote($this->adminId, $a['tenant_id'], 'Cliente pediu pausa');
        $this->platform->setTenantStatus($this->adminId, $a['tenant_id'], 'suspended');
        $s = $this->sub($a['tenant_id']);
        $this->assertSame('suspended', $s['status']);
        $this->assertSame('Inadimplência', $s['suspended_reason']);
        $this->assertSame('Cliente pediu pausa', $s['admin_note']);
        $actions = $this->pdo->query("SELECT action FROM audit_logs WHERE tenant_id = {$a['tenant_id']} AND user_id = {$this->adminId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['subscription.plan', 'subscription.renewed', 'subscription.status', 'platform.tenant_suspended'], $actions);
        // escritório suspenso perde o acesso na hora
        $this->expectException(Forbidden::class);
        $this->ctx($a['user_id'], $a['tenant_id']);
    }

    public function testUnknownTenantAndBadValues(): void
    {
        foreach ([fn () => $this->platform->tenant($this->adminId, 99999), fn () => $this->platform->setTenantStatus($this->adminId, 99999, 'active')] as $try) {
            try {
                $try();
                $this->fail();
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $a = $this->office('A', 'a@a.com');
        foreach ([fn () => $this->platform->setPlan($this->adminId, $a['tenant_id'], 'inexistente'), fn () => $this->platform->setTenantStatus($this->adminId, $a['tenant_id'], 'x'),
                     fn () => $this->platform->setSubscriptionStatus($this->adminId, $a['tenant_id'], 'x', null)] as $try) {
            try {
                $try();
                $this->fail();
            } catch (ValidationError) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
