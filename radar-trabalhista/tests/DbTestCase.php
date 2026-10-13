<?php
declare(strict_types=1);

namespace Radar\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Radar\Auth\AuthService;
use Radar\Migrator;
use Radar\Tenancy\TenantContext;
use Radar\Tests\Support\FixedClock;

/**
 * Base dos testes que usam banco de verdade (MariaDB/MySQL). Variáveis opcionais:
 * RADAR_TEST_DSN, RADAR_TEST_USER, RADAR_TEST_PASS. O banco de teste é APAGADO e recriado.
 */
abstract class DbTestCase extends TestCase
{
    private static ?PDO $shared = null;
    private static bool $migrated = false;
    protected PDO $pdo;
    protected FixedClock $clock;

    public static function connect(): PDO
    {
        return new PDO(
            getenv('RADAR_TEST_DSN') ?: 'mysql:host=localhost;dbname=radar_teste;charset=utf8mb4',
            getenv('RADAR_TEST_USER') ?: 'radar',
            getenv('RADAR_TEST_PASS') ?: 'radar_teste_local',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'"],
        );
    }

    protected function setUp(): void
    {
        try {
            self::$shared ??= self::connect();
        } catch (\PDOException $e) {
            $this->markTestSkipped('Banco de teste indisponível: ' . $e->getMessage());
        }
        $this->pdo = self::$shared;
        if (!self::$migrated) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $this->pdo->exec('DROP TABLE `' . $table . '`');
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            (new Migrator($this->pdo, __DIR__ . '/../migrations'))->migrate();
            self::$migrated = true;
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['tenant_notices', 'search_usage_daily', 'plan_requests', 'api_cache', 'api_rate', 'api_usage_daily', 'api_keys', 'api_clients', 'movement_actions', 'worker_runs', 'oab_alerts', 'oab_publications', 'oab_registrations', 'audit_logs', 'notifications', 'monitoring_events', 'monitoring_jobs', 'process_movements', 'processes',
                     'password_resets', 'subscriptions', 'tenant_members', 'users', 'tenants'] as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        // planos criados por testes específicos não vazam para os outros
        $this->pdo->exec("DELETE FROM plans WHERE code NOT IN ('essencial', 'profissional', 'equipe', 'por-busca')");
        $this->pdo->exec("DELETE FROM api_plans WHERE code NOT IN ('starter', 'pro', 'business', 'por-busca')");
        $this->pdo->exec("UPDATE api_plans SET active = 1, price_cents = CASE code WHEN 'starter' THEN 9900 WHEN 'pro' THEN 29900 ELSE 89900 END,
            monthly_quota = CASE code WHEN 'starter' THEN 2000 WHEN 'pro' THEN 15000 ELSE 100000 END, rate_per_minute = CASE code WHEN 'starter' THEN 30 WHEN 'pro' THEN 120 ELSE 600 END");
        $this->clock = new FixedClock();
    }

    protected function auth(): AuthService
    {
        return new AuthService($this->pdo, $this->clock);
    }

    /** @return array{tenant_id:int,user_id:int} um escritório com seu administrador (senha: "senha-segura-123") */
    protected function office(string $name, string $email, string $plan = 'essencial'): array
    {
        return $this->auth()->registerTenant($name, 'Admin ' . $name, $email, 'senha-segura-123', $plan);
    }

    protected function ctx(int $userId, int $tenantId): TenantContext
    {
        return TenantContext::resolve($this->pdo, $userId, $tenantId);
    }

    /** Acrescenta uma pessoa já ativa ao escritório com o papel dado. */
    protected function member(int $tenantId, string $email, string $role): int
    {
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([ucfirst($role), $email, AuthService::hashPassword('senha-segura-123'), '2026-10-10 12:00:00']);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO tenant_members (tenant_id, user_id, role, status, created_at) VALUES (?, ?, ?, 'active', '2026-10-10 12:00:00')")
            ->execute([$tenantId, $id, $role]);
        return $id;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** Números CNJ reais, com dígito verificador válido. */
    public const CNJ_TJMG = '0022929-16.2016.8.13.0297';
    public const CNJ_TJRO = '7064871-54.2025.8.22.0001';
    public const CNJ_TRT8 = '0001457-85.2026.5.08.0106';
    public const CNJ_TJMS = '5000250-61.2026.8.12.0022';
    public const CNJ_TJTO = '0000905-64.2026.8.27.2718';
    public const CNJ_STM = '7000133-21.2024.7.12.0012';
    public const CNJ_TRF4 = '5002305-61.2022.4.04.7109';
}
