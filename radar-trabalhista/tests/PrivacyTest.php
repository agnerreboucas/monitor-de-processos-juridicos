<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Monitoring\OabMonitor;
use Radar\Monitoring\Worker;
use Radar\Service\OabService;
use Radar\Service\PrivacyService;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;
use Radar\Tests\Support\FakeOabSource as F;

final class PrivacyTest extends DbTestCase
{
    private PrivacyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PrivacyService($this->pdo, $this->clock);
    }

    /** Escritório com processo + movimentação + notificação + OAB + publicação + alerta. */
    private function fullOffice(string $name, string $email, string $cnj = self::CNJ_TRT8): array
    {
        $o = $this->office($name, $email, 'profissional');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        (new ProcessService($this->pdo, $this->clock))->create($ctx, $cnj, "Cliente $name", $o['user_id']);
        $adapter = new FakeAdapter();
        $worker = new Worker($this->pdo, [$adapter], $this->clock, 'w');
        $adapter->thenFor($cnj, [FakeAdapter::movement("a$name")]);
        $worker->runBatch(10);
        $this->clock->advance('PT7H');
        $worker->enqueueDue();
        $adapter->thenFor($cnj, [FakeAdapter::movement("a$name"), FakeAdapter::movement("b$name", "Sentença $name")]);
        $worker->runBatch(10);
        (new OabService($this->pdo, $this->clock))->add($ctx, '1', 'MG', "Dr. $name");
        $pubs = [];
        for ($i = 1; $i <= 6; $i++) {
            $pubs[] = F::pub("$name$i", F::cnj($i, 2026, 8, '26'), '2026-02-01');
        }
        (new OabMonitor($this->pdo, (new F())->then($pubs), $this->clock))->runDue();
        $this->pdo->prepare("UPDATE oab_registrations SET next_check_at = '2100-01-01 00:00:00' WHERE tenant_id = ?")->execute([$o['tenant_id']]);   // não deixa o próximo escritório da fixture consultar esta OAB
        return $o;
    }

    private function rows(string $table, int $tenantId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM $table WHERE tenant_id = ?", [$tenantId]);
    }

    public function testExportHasEverythingOfTheOfficeAndNothingFromOthersOrSecrets(): void
    {
        $a = $this->fullOffice('Alfa', 'alfa@a.com');
        $b = $this->fullOffice('Beta', 'beta@b.com', self::CNJ_TJMG);
        $data = $this->svc->export($this->ctx($a['user_id'], $a['tenant_id']));
        $json = json_encode($data);
        $this->assertCount(1, $data['processes']);
        $this->assertCount(2, $data['movements']);
        $this->assertCount(6, $data['publications']);
        $this->assertSame('alfa@a.com', $data['people'][0]['email']);
        $this->assertStringNotContainsString('Beta', $json);
        $this->assertStringNotContainsString(self::CNJ_TJMG, $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('$argon', $json);
        $this->assertStringNotContainsString('$2y$', $json);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'privacy.exported' AND tenant_id = ?", [$a['tenant_id']]));
    }

    public function testOnlyTheOfficeAdminCanExportOrErase(): void
    {
        $a = $this->office('A', 'a@a.com');
        $lawyer = $this->ctx($this->member($a['tenant_id'], 'l@a.com', 'lawyer'), $a['tenant_id']);
        try {
            $this->svc->export($lawyer);
            $this->fail();
        } catch (Forbidden) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(Forbidden::class);
        $this->svc->eraseOffice($lawyer, 'Admin A', 'senha-segura-123');
    }

    public function testEraseNeedsExactNameAndPasswordAndChangesNothingOtherwise(): void
    {
        $a = $this->fullOffice('Alfa', 'alfa@a.com');
        $ctx = $this->ctx($a['user_id'], $a['tenant_id']);
        foreach ([['alfa', 'senha-segura-123'], ['Alfa', 'errada']] as [$name, $pass]) {
            try {
                $this->svc->eraseOffice($ctx, $name, $pass);
                $this->fail("apagou com $name/$pass");
            } catch (ValidationError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, $this->rows('processes', $a['tenant_id']));
    }

    public function testEraseRemovesEverythingOfTheOfficeAndKeepsTheOtherOfficeIntact(): void
    {
        $a = $this->fullOffice('Alfa', 'alfa@a.com');
        $b = $this->fullOffice('Beta', 'beta@b.com', self::CNJ_TJMG);
        $extra = $this->member($a['tenant_id'], 'extra@a.com', 'lawyer');
        $before = [];
        foreach (['processes', 'process_movements', 'notifications', 'monitoring_events', 'monitoring_jobs', 'oab_registrations', 'oab_publications', 'oab_alerts', 'audit_logs'] as $t) {
            $before[$t] = $this->rows($t, $b['tenant_id']);
            $this->assertGreaterThan(0, $this->rows($t, $a['tenant_id']), "fixture sem $t");
        }
        $this->svc->eraseOffice($this->ctx($a['user_id'], $a['tenant_id']), 'Alfa', 'senha-segura-123', '1.2.3.4');

        foreach ($before as $t => $n) {
            $this->assertSame(0, $this->rows($t === 'audit_logs' ? 'audit_logs' : $t, $a['tenant_id']), "sobrou $t");
            $this->assertSame($n, $this->rows($t, $b['tenant_id']), "mexeu em $t do outro escritório");
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tenants WHERE id = ?', [$a['tenant_id']]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM subscriptions WHERE tenant_id = ?', [$a['tenant_id']]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM users WHERE id IN (?, ?)', [$a['user_id'], $extra]));   // contas que só existiam aqui saem
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM users WHERE id = ?', [$b['user_id']]));
        $left = $this->pdo->query("SELECT action, user_id, tenant_id, meta FROM audit_logs WHERE action = 'privacy.office_erased'")->fetch();
        $this->assertNull($left['user_id']);
        $this->assertStringNotContainsString('alfa', (string) $left['meta']);
    }

    public function testUserOfTwoOfficesSurvivesTheErasureOfOne(): void
    {
        $a = $this->office('Alfa', 'alfa@a.com');
        $b = $this->office('Beta', 'beta@b.com');
        $this->pdo->prepare("INSERT INTO tenant_members (tenant_id, user_id, role, status, created_at) VALUES (?, ?, 'lawyer', 'active', NOW())")->execute([$b['tenant_id'], $a['user_id']]);
        $this->svc->eraseOffice($this->ctx($a['user_id'], $a['tenant_id']), 'Alfa', 'senha-segura-123');
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM users WHERE id = ?', [$a['user_id']]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tenant_members WHERE user_id = ? AND tenant_id = ?', [$a['user_id'], $b['tenant_id']]));
    }

    public function testPlatformAdminAccountIsNeverDeletedByAnOfficeErasure(): void
    {
        $a = $this->office('Alfa', 'alfa@a.com');
        $this->member($a['tenant_id'], 'dono@x.com', 'lawyer');
        $this->pdo->exec("UPDATE users SET is_platform_admin = 1 WHERE email = 'dono@x.com'");
        $this->svc->eraseOffice($this->ctx($a['user_id'], $a['tenant_id']), 'Alfa', 'senha-segura-123');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE email = 'dono@x.com'"));
    }

    public function testRetentionPurgesOldRecordsOnly(): void
    {
        $a = $this->office('A', 'a@a.com');
        $this->clock->advance('P800D');
        $this->pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, created_at) VALUES (?, ?, 'recente', ?)")->execute([$a['tenant_id'], $a['user_id'], '2028-12-01 00:00:00']);
        $r = $this->svc->purgeExpired();
        $this->assertGreaterThan(0, $r['audit']);                              // o registro do cadastro, com mais de 730 dias
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'recente'"));
        $this->assertSame(['audit' => 0, 'events' => 0, 'resets' => 0, 'api_cache' => 0, 'api_rate' => 0], $this->svc->purgeExpired());   // repetir não apaga mais nada
    }
}
