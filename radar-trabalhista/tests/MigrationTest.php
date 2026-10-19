<?php
declare(strict_types=1);

namespace Radar\Tests;

use PDOException;
use Radar\Migrator;

final class MigrationTest extends DbTestCase
{
    public function testAllTablesFromThePrdExist(): void
    {
        $prefix = (string) getenv('RADAR_TEST_PREFIX');
        $tables = array_map(static fn ($t) => $prefix !== '' && str_starts_with($t, $prefix) ? substr($t, strlen($prefix)) : $t, $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        foreach (['tenants', 'users', 'tenant_members', 'processes', 'process_movements', 'monitoring_jobs',
                     'monitoring_events', 'notifications', 'plans', 'subscriptions', 'audit_logs', 'integration_sources'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function testMigratingAgainChangesNothing(): void
    {
        $this->assertSame([], (new Migrator($this->pdo, __DIR__ . '/../migrations'))->migrate());
    }

    public function testInitialPlansAndSources(): void
    {
        $this->assertSame(['equipe', 'essencial', 'por-busca', 'profissional'],
            $this->pdo->query('SELECT code FROM plans ORDER BY code')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(['datajud', 'djen'],
            $this->pdo->query('SELECT code FROM integration_sources ORDER BY code')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** A garantia final contra mistura de dados: o banco recusa, mesmo que o código erre. */
    public function testDatabaseRefusesMovementOfAnotherTenantOnAProcess(): void
    {
        $a = $this->office('Escritório A', 'a@a.com');
        $b = $this->office('Escritório B', 'b@b.com');
        $this->pdo->prepare("INSERT INTO processes (tenant_id, cnj, cnj_digits, created_at) VALUES (?, ?, ?, '2026-10-10 12:00:00')")
            ->execute([$a['tenant_id'], self::CNJ_TJMG, '00229291620168130297']);
        $processOfA = (int) $this->pdo->lastInsertId();

        $this->expectException(PDOException::class);
        $this->pdo->prepare("INSERT INTO process_movements (tenant_id, process_id, source_id, external_id, captured_at, description)
                             VALUES (?, ?, 1, 'x', '2026-10-10 12:00:00', 'intruso')")
            ->execute([$b['tenant_id'], $processOfA]);          // escritório B, processo do A
    }

    public function testDatabaseRefusesNotificationAcrossTenants(): void
    {
        $a = $this->office('Escritório A', 'a@a.com');
        $b = $this->office('Escritório B', 'b@b.com');
        $this->pdo->prepare("INSERT INTO processes (tenant_id, cnj, cnj_digits, created_at) VALUES (?, ?, ?, '2026-10-10 12:00:00')")
            ->execute([$a['tenant_id'], self::CNJ_TJMG, '00229291620168130297']);
        $this->expectException(PDOException::class);
        $this->pdo->prepare("INSERT INTO notifications (tenant_id, user_id, process_id, movement_id, channel, created_at)
                             VALUES (?, ?, ?, 1, 'panel', '2026-10-10 12:00:00')")
            ->execute([$b['tenant_id'], $b['user_id'], (int) $this->pdo->lastInsertId()]);
    }
}
