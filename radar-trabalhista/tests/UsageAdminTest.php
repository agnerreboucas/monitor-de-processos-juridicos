<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Service\BillingService;
use Radar\Service\UsageAdminService;
use Radar\Tenancy\TenantContext;

final class UsageAdminTest extends DbTestCase
{
    private int $ownerId;
    private UsageAdminService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->office('Plataforma', 'dono@saas.com');
        $this->ownerId = $owner['user_id'];
        $this->pdo->exec('UPDATE users SET is_platform_admin = 1 WHERE id = ' . $this->ownerId);
        $this->svc = new UsageAdminService($this->pdo, $this->clock);
    }

    public function testOverviewCountsOfficesUsersSearchesAndRevenue(): void
    {
        $a = $this->office('Alfa', 'a@alfa.com', 'por-busca');
        $b = $this->office('Beta', 'b@beta.com', 'por-busca');
        $this->pdo->exec("UPDATE subscriptions SET status = 'active' WHERE tenant_id = " . $a['tenant_id']);
        $this->pdo->exec('UPDATE users SET last_login_at = ' . "'2026-10-09 10:00:00'" . ' WHERE id = ' . $a['user_id']);
        $bill = new BillingService($this->pdo, $this->clock);
        for ($i = 0; $i < 4; $i++) {
            $bill->record($a['tenant_id'], 'process', true);
        }
        $bill->record($a['tenant_id'], 'process', false);
        $bill->record($b['tenant_id'], 'oab', true);

        $o = $this->svc->overview($this->ownerId, 30);
        $this->assertSame(5, $o['searches']['ok']);
        $this->assertSame(1, $o['searches']['failed']);
        $this->assertSame('Alfa', $o['top_tenants'][0]['name']);
        $this->assertSame(120, $o['revenue_month_cents']);                    // Alfa: 4 × R$ 0,30 (ativa). Beta está em teste: 0. A plataforma (plano mensal em teste) também 0
        $this->assertSame(1, $o['users']['active_7d']);
        $this->assertCount(30, $o['series']);
        $this->assertSame(5, array_sum(array_column($o['series'], 'ok')));
        $this->assertSame(3, $o['tenants']['total']);
        $this->assertSame(3, array_sum(array_column($o['series'], 'signups')));
    }

    public function testUserListFiltersAndDetail(): void
    {
        $a = $this->office('Alfa', 'a@alfa.com');
        $this->office('Beta', 'b@beta.com');
        $all = $this->svc->users($this->ownerId);
        $this->assertSame(3, $all['total']);
        $alfa = $this->svc->users($this->ownerId, 'alfa');
        $this->assertSame(1, $alfa['total']);
        $this->assertStringContainsString('Alfa (office_admin)', $alfa['items'][0]['offices']);
        $this->assertSame(3, $this->svc->users($this->ownerId, '', '', '30')['total']);          // ninguém entrou nos últimos 30 dias
        $d = $this->svc->user($this->ownerId, $a['user_id']);
        $this->assertSame('a@alfa.com', $d['user']['email']);
        $this->assertSame('Alfa', $d['offices'][0]['name']);
        $this->assertArrayNotHasKey('password_hash', $d['user']);
    }

    public function testBlockingAUserCutsAccessAndProtectsAdmins(): void
    {
        $a = $this->office('Alfa', 'a@alfa.com');
        $this->svc->setUserStatus($this->ownerId, $a['user_id'], 'disabled');
        $this->expectException(Forbidden::class);
        try {
            TenantContext::resolve($this->pdo, $a['user_id'], $a['tenant_id']);               // uma sessão já aberta perde o acesso no próximo pedido
        } finally {
            $this->svc->setUserStatus($this->ownerId, $a['user_id'], 'active');
            $this->assertNotNull(TenantContext::resolve($this->pdo, $a['user_id'], $a['tenant_id']));
        }
    }

    public function testCannotBlockYourselfOrAnotherPlatformAdmin(): void
    {
        $second = $this->office('Outra', 'o@o.com');
        $this->pdo->exec('UPDATE users SET is_platform_admin = 1 WHERE id = ' . $second['user_id']);
        try {
            $this->svc->setUserStatus($this->ownerId, $this->ownerId, 'disabled');
            $this->fail('não pode se bloquear');
        } catch (ValidationError) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ValidationError::class);
        $this->svc->setUserStatus($this->ownerId, $second['user_id'], 'disabled');
    }

    public function testTenantUsageHasSeriesStatementAndMembers(): void
    {
        $a = $this->office('Alfa', 'a@alfa.com', 'por-busca');
        $bill = new BillingService($this->pdo, $this->clock);
        $bill->record($a['tenant_id'], 'process', true);
        $bill->record($a['tenant_id'], 'lookup', false);
        $t = $this->svc->tenant($this->ownerId, $a['tenant_id'], 14);
        $this->assertCount(14, $t['series']);
        $this->assertSame([1, 1], [$t['statement']['ok'], $t['statement']['failed']]);
        $this->assertSame('a@alfa.com', $t['members'][0]['email']);
        $this->assertSame(0, $t['counts']['processes']);
    }

    public function testOnlyThePlatformOwnerSeesAnything(): void
    {
        $a = $this->office('Alfa', 'a@alfa.com');
        foreach ([fn () => $this->svc->overview($a['user_id']), fn () => $this->svc->users($a['user_id']), fn () => $this->svc->user($a['user_id'], 1), fn () => $this->svc->tenant($a['user_id'], $a['tenant_id'])] as $call) {
            try {
                $call();
                $this->fail('deveria negar');
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
