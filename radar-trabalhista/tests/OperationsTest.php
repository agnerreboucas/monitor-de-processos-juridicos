<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Http\App;
use Radar\Http\ArraySession;
use Radar\Http\Request;
use Radar\Support\Backup;
use Radar\Tests\Support\FakeMailer;

final class OperationsTest extends DbTestCase
{
    private function health(): array
    {
        $app = new App($this->pdo, new ArraySession(), new FakeMailer(), 'https://x', $this->clock);
        $r = $app->handle(new Request('GET', '/api/health', [], [], [], '10.0.0.1', true));
        return ['status' => $r->status, 'json' => json_decode($r->body, true)];
    }

    public function testHealthIsPublicAndReportsOnlyOkOrDegraded(): void
    {
        $h = $this->health();
        $this->assertSame(503, $h['status']);                                  // nenhuma rodada do robô registrada
        $this->assertSame(['status', 'db', 'worker'], array_keys($h['json']));
        $this->pdo->prepare('INSERT INTO worker_runs (started_at, finished_at) VALUES (?, ?)')->execute(['2026-10-10 11:58:00', '2026-10-10 11:58:10']);
        $this->assertSame(200, $this->health()['status']);
        $this->clock->advance('PT25M');
        $this->assertSame(503, $this->health()['status']);                     // robô parado há mais de 20 min
    }

    public function testBackupCanBeRestoredAndRefusesToKeepABrokenFile(): void
    {
        if (trim((string) shell_exec('command -v mysqldump') ?: '') === '' || trim((string) shell_exec('command -v mysql') ?: '') === '') {
            $this->markTestSkipped('mysqldump/mysql indisponíveis.');
        }
        $o = $this->office('Escritório do Backup', 'bk@a.com');
        $dir = sys_get_temp_dir() . '/radar-bk-' . bin2hex(random_bytes(4));
        $dsn = getenv('RADAR_TEST_DSN') ?: 'mysql:host=localhost;dbname=radar_teste;charset=utf8mb4';
        preg_match('/host=([^;]+)/', $dsn, $h);
        preg_match('/dbname=([^;]+)/', $dsn, $d);
        $user = getenv('RADAR_TEST_USER') ?: 'radar';
        $pass = getenv('RADAR_TEST_PASS') ?: 'radar_teste_local';
        $backup = new Backup($h[1], 3306, $d[1], $user, $pass, $dir);

        $file = $backup->run(14, new \DateTimeImmutable('2026-10-10 03:00:00'));
        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertStringContainsString('Escritório do Backup', implode('', gzfile($file)));
        $this->assertStringNotContainsString($pass, implode('', gzfile($file)));

        // desastre: o escritório some; a restauração traz de volta
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('TRUNCATE TABLE subscriptions');
        $this->pdo->exec('TRUNCATE TABLE tenant_members');
        $this->pdo->exec('TRUNCATE TABLE tenants');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tenants'));
        $cmd = sprintf('gunzip -c %s | MYSQL_PWD=%s mysql --host=%s -u%s %s 2>&1', escapeshellarg($file), escapeshellarg($pass), escapeshellarg($h[1]), escapeshellarg($user), escapeshellarg($d[1]));
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame('Escritório do Backup', $this->scalar('SELECT name FROM tenants WHERE id = ?', [$o['tenant_id']]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM subscriptions WHERE tenant_id = ?', [$o['tenant_id']]));

        // mantém só as N mais recentes
        foreach (['2026-10-11 03:00:00', '2026-10-12 03:00:00', '2026-10-13 03:00:00'] as $when) {
            $backup->run(2, new \DateTimeImmutable($when));
        }
        $this->assertCount(2, glob($dir . '/radar-*.sql.gz'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testBackupFailureLeavesNoFile(): void
    {
        $dir = sys_get_temp_dir() . '/radar-bk-' . bin2hex(random_bytes(4));
        $backup = new Backup('127.0.0.1', 1, 'nao_existe', 'ninguem', 'x', $dir);
        try {
            $backup->run();
            $this->fail('deveria falhar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mysqldump falhou', $e->getMessage());
        }
        $this->assertSame([], glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
}
