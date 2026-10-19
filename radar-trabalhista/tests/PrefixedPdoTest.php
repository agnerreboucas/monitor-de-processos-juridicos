<?php
declare(strict_types=1);

namespace Radar\Tests;

use PHPUnit\Framework\TestCase;
use Radar\Support\PrefixedPdo;

final class PrefixedPdoTest extends TestCase
{
    private function pdo(): PrefixedPdo
    {
        $dsn = getenv('RADAR_TEST_DSN') ?: 'mysql:host=localhost;dbname=radar_teste;charset=utf8mb4';
        try {
            return new PrefixedPdo($dsn, getenv('RADAR_TEST_USER') ?: 'radar', getenv('RADAR_TEST_PASS') ?: 'radar_teste_local',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION], 'wp_radar_', ['tenants', 'users', 'plans', 'processes', 'process_movements', 'schema_migrations']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Banco de teste indisponível.');
        }
    }

    public function testPrefixesTablesButNotTextsOrColumnsOrOtherPrefixes(): void
    {
        $p = $this->pdo();
        $this->assertSame('SELECT * FROM wp_radar_tenants t JOIN wp_radar_users u ON u.id = t.owner', $p->rewrite('SELECT * FROM tenants t JOIN users u ON u.id = t.owner'));
        $this->assertSame("SELECT 1 FROM wp_radar_plans WHERE code = 'plans' AND note = 'it''s users'", $p->rewrite("SELECT 1 FROM plans WHERE code = 'plans' AND note = 'it''s users'"));
        $this->assertSame('SELECT users_used, t.processes_total FROM wp_radar_process_movements', $p->rewrite('SELECT users_used, t.processes_total FROM process_movements'));
        $this->assertSame('SELECT COUNT(*) AS processes, u.id AS  users FROM wp_radar_processes p', $p->rewrite('SELECT COUNT(*) AS processes, u.id AS  users FROM processes p'));
        $this->assertSame('SELECT * FROM wp_users', $p->rewrite('SELECT * FROM wp_users'));              // tabela de outro sistema fica como está
        $this->assertSame('DROP TABLE `wp_radar_plans`', $p->rewrite('DROP TABLE `wp_radar_plans`'));      // nome já prefixado não prefixa de novo
    }

    public function testTablesAreFoundInTheMigrations(): void
    {
        $t = PrefixedPdo::tablesFrom(__DIR__ . '/../migrations');
        foreach (['schema_migrations', 'tenants', 'users', 'processes', 'tenant_notices', 'search_usage_daily', 'api_keys'] as $name) {
            $this->assertContains($name, $t);
        }
    }

    public function testRejectsAnInvalidPrefixFromTheConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Radar\Database::connect(new \Radar\Config(['DB_PREFIX' => 'wp; DROP']));
    }
}
