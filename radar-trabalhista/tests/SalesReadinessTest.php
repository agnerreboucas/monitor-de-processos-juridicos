<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Config;
use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Service\ReadinessService;
use Radar\Service\SalesService;
use Radar\Tests\Support\FakeMailer;

final class SalesReadinessTest extends DbTestCase
{
    private FakeMailer $mailer;
    private SalesService $sales;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new FakeMailer();
        $this->sales = new SalesService($this->pdo, $this->mailer, $this->clock);
        $owner = $this->office('Plataforma', 'dono@saas.com');
        $this->ownerId = $owner['user_id'];
        $this->pdo->exec('UPDATE users SET is_platform_admin = 1 WHERE id = ' . $this->ownerId);
    }

    public function testOfficeAdminRequestsAPlanAndTheOwnerIsNotified(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $r = $this->sales->request($ctx, 'profissional', 'Quero pagar no Pix');
        $this->assertSame('Profissional', $r['plan_name']);
        $this->assertSame('profissional', $this->sales->openFor($ctx)['plan_code']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('dono@saas.com', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('Silva', $this->mailer->sent[0]['body']);
        $this->assertStringContainsString('Quero pagar no Pix', $this->mailer->sent[0]['body']);
        $list = $this->sales->openRequests($this->ownerId);
        $this->assertCount(1, $list);
        $this->assertSame('Silva', $list[0]['tenant_name']);
    }

    public function testOnlyOneOpenRequestPerOfficeAndTheRequestStaysEvenIfMailFails(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $this->mailer->failNext = 5;
        $this->sales->request($ctx, 'essencial', null);
        $this->sales->request($ctx, 'equipe', null);
        $this->assertSame('equipe', $this->sales->openFor($ctx)['plan_code']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM plan_requests WHERE status = 'open'"));
    }

    public function testInvalidPlanAndNonAdminMembersAreRefused(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        try {
            $this->sales->request($ctx, 'inexistente', null);
            $this->fail('plano inexistente');
        } catch (ValidationError) {
            $this->addToAssertionCount(1);
        }
        $assistant = $this->member($o['tenant_id'], 'ass@silva.com', 'assistant');
        $this->expectException(Forbidden::class);
        $this->sales->request($this->ctx($assistant, $o['tenant_id']), 'essencial', null);
    }

    public function testOwnerResolvesRequestsAndOthersCannotSeeThem(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $this->sales->request($ctx, 'essencial', null);
        $id = (int) $this->scalar('SELECT id FROM plan_requests');
        try {
            $this->sales->openRequests($o['user_id']);
            $this->fail('quem não é dono não vê pedidos');
        } catch (Forbidden) {
            $this->addToAssertionCount(1);
        }
        $this->sales->resolve($this->ownerId, $id, 'done');
        $this->assertSame([], $this->sales->openRequests($this->ownerId));
        $this->assertNull($this->sales->openFor($ctx));
        $this->expectException(NotFound::class);
        $this->sales->resolve($this->ownerId, $id, 'done');                 // já tratado
    }

    // ── "pronto para vender?" ────────────────────────────────────────────────

    private function readiness(array $env, string $publicDir, string $backupDir): ReadinessService
    {
        return new ReadinessService($this->pdo, new Config($env), $publicDir, $backupDir, $this->clock);
    }

    /** @return array<string,array{status:string,detail:string}> */
    private function byId(array $checks): array
    {
        return array_column(array_map(fn ($c) => ['id' => $c['id'], 'status' => $c['status'], 'detail' => $c['detail']], $checks), null, 'id');
    }

    public function testFreshInstallFlagsWhatIsMissing(): void
    {
        $tmp = sys_get_temp_dir() . '/radar-ready-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/app', 0777, true);
        file_put_contents($tmp . '/app/termos.html', '<p>RASCUNHO [PREENCHER]</p>');
        file_put_contents($tmp . '/app/privacidade.html', '<p>RASCUNHO</p>');
        $c = $this->byId($this->readiness([], $tmp, $tmp . '/backups')->checks($this->ownerId));
        foreach (['url', 'worker', 'smtp', 'backup', 'legal', 'payment'] as $id) {
            $this->assertSame('fail', $c[$id]['status'], $id);
        }
        $this->assertSame('warn', $c['source']['status']);
        $this->assertSame('warn', $c['datajud']['status']);
        $this->assertSame('ok', $c['plans']['status']);
        $this->assertStringContainsString('Termos de Uso e Política de Privacidade', $c['legal']['detail']);
    }

    public function testConfiguredInstallPasses(): void
    {
        $tmp = sys_get_temp_dir() . '/radar-ready-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/app', 0777, true);
        mkdir($tmp . '/backups', 0777, true);
        file_put_contents($tmp . '/app/termos.html', '<p>Termos revisados.</p>');
        file_put_contents($tmp . '/app/privacidade.html', '<p>Privacidade revisada.</p>');
        $dump = $tmp . '/backups/radar-20261010-030000.sql.gz';
        file_put_contents($dump, 'x');
        touch($dump, $this->clock->now()->getTimestamp() - 3600);
        $this->pdo->prepare('INSERT INTO worker_runs (started_at) VALUES (?)')->execute([$this->clock->now()->format('Y-m-d H:i:s')]);
        $env = ['APP_URL' => 'https://radar.exemplo.com.br', 'SMTP_HOST' => 'smtp.x', 'SMTP_FROM' => 'a@x', 'PAYMENT_INFO' => 'Pix', 'SUPPORT_CONTACT' => 'a@x', 'DATAJUD_API_KEY' => 'k'];
        $c = $this->byId($this->readiness($env, $tmp, $tmp . '/backups')->checks($this->ownerId));
        foreach ($c as $id => $row) {
            $this->assertContains($row['status'], ['ok', 'warn'], $id);
        }
        $this->assertSame('ok', $c['legal']['status']);
        $this->assertSame('ok', $c['backup']['status']);
        $this->assertSame('ok', $c['worker']['status']);
        $this->assertSame('ok', $c['payment']['status']);
        $this->assertStringNotContainsString('Pix', json_encode($c['payment']));          // não repete o conteúdo configurado
    }

    public function testReadinessIsForTheOwnerOnly(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $this->expectException(Forbidden::class);
        $this->readiness([], sys_get_temp_dir(), sys_get_temp_dir())->checks($o['user_id']);
    }
}
