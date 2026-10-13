<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Http\App;
use Radar\Http\ArraySession;
use Radar\Http\Request;
use Radar\Http\Response;
use Radar\Tests\Support\FakeMailer;

/** A API de ponta a ponta, sem servidor web: Request entra, Response sai. */
final class HttpApiTest extends DbTestCase
{
    private ArraySession $session;
    private FakeMailer $mailer;
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->newClient();
    }

    private function newClient(string $ip = '10.0.0.1'): void
    {
        $this->session = new ArraySession();
        $this->mailer = new FakeMailer();
        $this->app = new App($this->pdo, $this->session, $this->mailer, 'https://radar.exemplo.com.br', $this->clock);
        $this->ip = $ip;
    }

    private string $ip = '10.0.0.1';

    /** @return array<string,mixed> */
    private function call(string $method, string $path, array $body = [], array $query = [], ?array $headers = null): array
    {
        $response = $this->raw($method, $path, $body, $query, $headers);
        return ['status' => $response->status, 'json' => json_decode($response->body, true) ?? [], 'headers' => $response->headers];
    }

    private function raw(string $method, string $path, array $body = [], array $query = [], ?array $headers = null): Response
    {
        $csrf = (string) ($this->session->data['csrf'] ?? '');
        $headers ??= ['content-type' => 'application/json', 'x-csrf-token' => $csrf];
        return $this->app->handle(new Request($method, $path, $query, $body, $headers, $this->ip, true));
    }

    private function registerAndLogin(string $office = 'Silva Advocacia', string $email = 'dra@silva.com'): void
    {
        $this->call('GET', '/api/session');
        $r = $this->call('POST', '/api/register', ['office_name' => $office, 'name' => 'Dra. Silva', 'email' => $email, 'password' => 'senha-segura-123', 'accept_terms' => true]);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
    }

    // ── segurança da sessão ──────────────────────────────────────────────────

    public function testSecurityHeadersOnEveryResponse(): void
    {
        $h = $this->call('GET', '/api/session')['headers'];
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('DENY', $h['X-Frame-Options']);
        $this->assertSame('no-store', $h['Cache-Control']);
        $this->assertStringContainsString("frame-ancestors 'none'", $h['Content-Security-Policy']);
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $this->call('GET', '/api/session');
        $body = ['office_name' => 'X', 'name' => 'Y', 'email' => 'x@x.com', 'password' => 'senha-segura-123'];
        foreach ([
            'sem token' => ['content-type' => 'application/json'],
            'token errado' => ['content-type' => 'application/json', 'x-csrf-token' => str_repeat('a', 64)],
            'sem JSON' => ['x-csrf-token' => (string) $this->session->data['csrf'], 'content-type' => 'text/plain'],
        ] as $label => $headers) {
            $r = $this->call('POST', '/api/register', $body, [], $headers);
            $this->assertSame(403, $r['status'], $label);
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tenants'));
    }

    public function testRejectsOtherSitesOrigin(): void
    {
        $this->call('GET', '/api/session');
        $headers = ['content-type' => 'application/json', 'x-csrf-token' => (string) $this->session->data['csrf'], 'origin' => 'https://site-malicioso.exemplo'];
        $this->assertSame(403, $this->call('POST', '/api/login', ['email' => 'a@a.com', 'password' => 'x'], [], $headers)['status']);
        $headers['origin'] = 'https://radar.exemplo.com.br';
        $this->assertSame(401, $this->call('POST', '/api/login', ['email' => 'a@a.com', 'password' => 'x'], [], $headers)['status']);   // passou do CSRF
    }

    public function testProtectedRoutesNeedLogin(): void
    {
        $this->call('GET', '/api/session');
        foreach (['/api/dashboard', '/api/processes', '/api/notifications', '/api/team', '/api/plan', '/api/audit', '/api/processes/1', '/api/oabs', '/api/publications', '/api/oab-stats', '/api/admin/overview', '/api/admin/tenants'] as $path) {
            $this->assertSame(401, $this->call('GET', $path)['status'], $path);
        }
        $this->assertSame(401, $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJMG])['status']);
    }

    public function testLoginRenewsTheSessionAndLogoutEndsIt(): void
    {
        $this->registerAndLogin();
        $this->assertGreaterThanOrEqual(1, $this->session->regenerations);               // registro já entrou com sessão nova
        $before = $this->session->regenerations;
        $this->call('POST', '/api/logout');
        $this->assertTrue($this->session->destroyed);
        $this->assertSame(401, $this->call('GET', '/api/dashboard')['status']);
        $this->call('GET', '/api/session');
        $this->call('POST', '/api/login', ['email' => 'dra@silva.com', 'password' => 'senha-segura-123']);
        $this->assertSame($before + 1, $this->session->regenerations);                    // login = nova sessão (contra fixação)
    }

    public function testSessionExpiresWhenIdleOrTooOld(): void
    {
        $this->registerAndLogin();
        $this->assertSame(200, $this->call('GET', '/api/dashboard')['status']);
        $this->clock->advance('PT2H1M');
        $this->assertSame(401, $this->call('GET', '/api/dashboard')['status']);           // 2 h parado
    }

    public function testUnknownRouteAndMethodsAreRefused(): void
    {
        $this->registerAndLogin();
        $this->assertSame(404, $this->call('GET', '/api/nada')['status']);
        $this->assertSame(404, $this->call('DELETE', '/api/processes/1')['status']);
        $this->assertSame(404, $this->call('GET', '/qualquer-coisa')['status']);
    }

    public function testMalformedBodyIsAClearError(): void
    {
        $this->call('GET', '/api/session');
        $r = $this->call('POST', '/api/login', ['__invalid' => true]);
        $this->assertSame(422, $r['status']);
    }

    // ── a regra de ouro: o escritório vem da sessão ──────────────────────────

    public function testTenantFromTheRequestIsIgnored(): void
    {
        $this->registerAndLogin('Escritório A', 'a@a.com');
        $a = (int) $this->scalar("SELECT id FROM tenants WHERE name = 'Escritório A'");
        $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJMG, 'client_label' => 'Do A']);
        $this->newClient('10.0.0.2');
        $this->registerAndLogin('Escritório B', 'b@b.com');
        $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJRO, 'client_label' => 'Do B']);
        $b = (int) $this->scalar("SELECT id FROM tenants WHERE name = 'Escritório B'");

        // B tenta se passar por A de todas as formas que um atacante tentaria
        $list = $this->call('GET', '/api/processes', [], ['tenant_id' => (string) $a, 'tenant' => (string) $a]);
        $this->assertSame([self::CNJ_TJRO], array_column($list['json']['data']['items'], 'cnj'));
        $r = $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TRT8, 'tenant_id' => $a]);
        $this->assertSame($b, (int) $this->scalar('SELECT tenant_id FROM processes WHERE cnj = ?', [self::CNJ_TRT8]));
        $this->assertSame(201, $r['status']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND cnj = ?', [$a, self::CNJ_TRT8]));
    }

    public function testCannotSelectAnOfficeYouDoNotBelongTo(): void
    {
        $this->registerAndLogin('Escritório A', 'a@a.com');
        $this->newClient('10.0.0.2');
        $this->registerAndLogin('Escritório B', 'b@b.com');
        $a = (int) $this->scalar("SELECT id FROM tenants WHERE name = 'Escritório A'");
        $this->assertSame(403, $this->call('POST', '/api/tenant/select', ['tenant_id' => $a])['status']);
        // forjar o valor direto na sessão (como se o PHP tivesse sido adulterado) também não dá acesso: o banco é reconferido
        $this->session->set('tenant_id', $a);
        $this->assertSame(403, $this->call('GET', '/api/processes')['status']);
        $this->assertNull($this->session->get('tenant_id'));
    }

    public function testAnotherOfficesProcessIs404(): void
    {
        $this->registerAndLogin('Escritório A', 'a@a.com');
        $id = $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJMG])['json']['data']['id'];
        $this->newClient('10.0.0.2');
        $this->registerAndLogin('Escritório B', 'b@b.com');
        foreach ([['GET', ''], ['PATCH', ''], ['POST', '/pause'], ['POST', '/archive'], ['POST', '/check']] as [$method, $suffix]) {
            $this->assertSame(404, $this->call($method, "/api/processes/$id$suffix", ['client_label' => 'x'])['status'], "$method $suffix");
        }
        $this->assertSame('active', $this->scalar('SELECT status FROM processes WHERE id = ?', [$id]));
    }

    public function testAccessIsLostImmediatelyWhenDisabled(): void
    {
        $this->registerAndLogin();
        $this->assertSame(200, $this->call('GET', '/api/processes')['status']);
        $this->pdo->exec("UPDATE tenant_members SET status = 'disabled'");
        $this->assertSame(403, $this->call('GET', '/api/processes')['status']);
    }

    // ── fluxos ───────────────────────────────────────────────────────────────

    public function testRegisterLoginAndWorkWithProcesses(): void
    {
        $this->registerAndLogin();
        $me = $this->call('GET', '/api/session')['json']['data'];
        $this->assertTrue($me['authenticated']);
        $this->assertSame('office_admin', $me['tenant']['role']);

        $created = $this->call('POST', '/api/processes', ['cnj' => '00014578520265080106', 'client_label' => 'Maria', 'notes' => 'urgente']);
        $this->assertSame(201, $created['status']);
        $id = $created['json']['data']['id'];
        $this->assertSame(self::CNJ_TRT8, $created['json']['data']['cnj']);

        $detail = $this->call('GET', "/api/processes/$id")['json']['data'];
        $this->assertSame('Maria', $detail['process']['client_label']);
        $this->assertSame([], $detail['movements']);

        $this->assertSame('Novo', $this->call('PATCH', "/api/processes/$id", ['client_label' => 'Novo'])['json']['data']['client_label']);
        $this->call('POST', "/api/processes/$id/pause");
        $this->assertSame('paused', $this->call('GET', "/api/processes/$id")['json']['data']['process']['status']);

        $dash = $this->call('GET', '/api/dashboard')['json']['data'];
        $this->assertSame(1, $dash['processes_total']);
        $this->assertSame(1, $this->call('GET', '/api/plan')['json']['data']['processes_used']);
    }

    public function testValidationErrorsAre422WithAClearMessage(): void
    {
        $this->registerAndLogin();
        $r = $this->call('POST', '/api/processes', ['cnj' => '123']);
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('inválido', $r['json']['error']);
        $this->assertSame('validation', $r['json']['code']);
    }

    public function testPlanLimitIs409(): void
    {
        $this->pdo->exec("UPDATE plans SET max_processes = 1 WHERE code = 'essencial'");
        $this->registerAndLogin();
        $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJMG]);
        $r = $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJRO]);
        $this->assertSame(409, $r['status']);
        $this->assertSame('limit', $r['json']['code']);
        $this->pdo->exec("UPDATE plans SET max_processes = 50 WHERE code = 'essencial'");
    }

    public function testListFiltersAndPagination(): void
    {
        $this->registerAndLogin();
        foreach ([self::CNJ_TJMG => 'Ana', self::CNJ_TJRO => 'Bia', self::CNJ_TRT8 => 'Carla'] as $cnj => $name) {
            $this->call('POST', '/api/processes', ['cnj' => $cnj, 'client_label' => $name]);
        }
        $this->assertSame(3, $this->call('GET', '/api/processes')['json']['data']['total']);
        $this->assertSame(['Bia'], array_column($this->call('GET', '/api/processes', [], ['q' => 'bia'])['json']['data']['items'], 'client_label'));
        $this->assertSame(1, $this->call('GET', '/api/processes', [], ['tribunal' => 'TRT8'])['json']['data']['total']);
        $page = $this->call('GET', '/api/processes', [], ['per_page' => '2', 'page' => '2'])['json']['data'];
        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['items']);
    }

    // ── senha, convites, e-mail ──────────────────────────────────────────────

    public function testForgotPasswordRevealsNothingAndSendsALink(): void
    {
        $this->registerAndLogin();
        $this->call('POST', '/api/logout');
        $this->call('GET', '/api/session');
        $known = $this->call('POST', '/api/password/forgot', ['email' => 'dra@silva.com']);
        $unknown = $this->call('POST', '/api/password/forgot', ['email' => 'ninguem@silva.com']);
        $this->assertSame($known['json'], $unknown['json']);                              // respostas idênticas
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('dra@silva.com', $this->mailer->sent[0]['to']);
        $this->assertStringNotContainsString('token', $known['json']['message']);
        preg_match('~token=([0-9a-f]{64})~', $this->mailer->sent[0]['body'], $m);
        $this->assertNotEmpty($m);

        $this->assertSame(200, $this->call('POST', '/api/password/reset', ['token' => $m[1], 'password' => 'nova-senha-forte-9'])['status']);
        $this->assertSame(200, $this->call('POST', '/api/login', ['email' => 'dra@silva.com', 'password' => 'nova-senha-forte-9'])['status']);
        $this->assertSame(422, $this->call('POST', '/api/password/reset', ['token' => $m[1], 'password' => 'outra-senha-forte-9'])['status']);
    }

    public function testInviteSendsAnEmailAndTheGuestCanJoin(): void
    {
        $this->registerAndLogin();
        $r = $this->call('POST', '/api/team/invite', ['name' => 'Dr. Souza', 'email' => 'souza@silva.com', 'role' => 'lawyer']);
        $this->assertSame(201, $r['status']);
        $this->assertTrue($r['json']['data']['email_sent']);
        preg_match('~token=([0-9a-f]{64})~', $this->mailer->sent[0]['body'], $m);
        $this->assertNotEmpty($m);
        $this->assertCount(2, $this->call('GET', '/api/team')['json']['data']);

        $this->newClient('10.0.0.9');
        $this->call('GET', '/api/session');
        $this->call('POST', '/api/password/reset', ['token' => $m[1], 'password' => 'senha-do-souza-1']);
        $login = $this->call('POST', '/api/login', ['email' => 'souza@silva.com', 'password' => 'senha-do-souza-1']);
        $this->assertSame('lawyer', $login['json']['data']['tenants'][0]['role']);
        $this->assertSame(403, $this->call('POST', '/api/team/invite', ['name' => 'X', 'email' => 'x@silva.com', 'role' => 'lawyer'])['status']);   // advogado não convida
    }

    public function testMailFailureDoesNotLoseTheInvite(): void
    {
        $this->registerAndLogin();
        $this->mailer->failNext = 1;
        $r = $this->call('POST', '/api/team/invite', ['name' => 'Dr. Souza', 'email' => 'souza@silva.com', 'role' => 'lawyer']);
        $this->assertSame(201, $r['status']);
        $this->assertFalse($r['json']['data']['email_sent']);                              // a tela avisa que o e-mail não saiu
    }

    // ── notificações ─────────────────────────────────────────────────────────

    public function testNotificationsListAndMarkAsRead(): void
    {
        $this->registerAndLogin();
        $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TJMG]);
        $worker = new \Radar\Monitoring\Worker($this->pdo, [(new Support\FakeAdapter())
            ->thenFor(self::CNJ_TJMG, [Support\FakeAdapter::movement('1')])
            ->thenFor(self::CNJ_TJMG, [Support\FakeAdapter::movement('1'), Support\FakeAdapter::movement('2', 'Sentença publicada')])], $this->clock);
        $worker->runBatch(10);
        $this->clock->advance('PT7H');
        $worker->enqueueDue();
        $worker->runBatch(10);

        // passaram 7 h: a sessão expirou por inatividade (2 h), então entra de novo
        $this->assertSame(401, $this->call('GET', '/api/notifications')['status']);
        $this->call('GET', '/api/session');
        $this->call('POST', '/api/login', ['email' => 'dra@silva.com', 'password' => 'senha-segura-123']);

        $list = $this->call('GET', '/api/notifications')['json']['data'];
        $this->assertSame(1, $list['unread']);
        $this->assertStringContainsString('Sentença publicada', $list['items'][0]['description']);
        $this->assertTrue($this->call('POST', '/api/notifications/' . $list['items'][0]['id'] . '/read')['json']['ok']);
        $this->assertSame(0, $this->call('GET', '/api/notifications')['json']['data']['unread']);
        $this->assertFalse($this->call('POST', '/api/notifications/' . $list['items'][0]['id'] . '/read')['json']['ok']);   // já lida
    }

    // ── abuso ────────────────────────────────────────────────────────────────

    public function testRegistrationFloodFromOneIpIsBlocked(): void
    {
        $this->call('GET', '/api/session');
        for ($i = 1; $i <= 5; $i++) {
            $this->newClient();
            $this->call('GET', '/api/session');
            $this->assertSame(201, $this->call('POST', '/api/register', ['office_name' => "E$i", 'name' => 'N', 'email' => "e$i@x.com", 'password' => 'senha-segura-123', 'accept_terms' => true])['status']);
        }
        $this->newClient();
        $this->call('GET', '/api/session');
        $this->assertSame(409, $this->call('POST', '/api/register', ['office_name' => 'E6', 'name' => 'N', 'email' => 'e6@x.com', 'password' => 'senha-segura-123', 'accept_terms' => true])['status']);
    }

    public function testBruteForceFromOneIpIsBlockedEvenAcrossAccounts(): void
    {
        $this->office('A', 'a@a.com');
        $this->call('GET', '/api/session');
        for ($i = 0; $i < 20; $i++) {
            $this->call('POST', '/api/login', ['email' => "alvo$i@x.com", 'password' => 'chute-chute-1']);
        }
        $r = $this->call('POST', '/api/login', ['email' => 'a@a.com', 'password' => 'senha-segura-123']);   // até a senha certa é barrada
        $this->assertSame(401, $r['status']);
        $this->assertStringContainsString('Muitas tentativas', $r['json']['error']);
    }

    public function testUnexpectedErrorsAreGenericAndNeverLeakDetails(): void
    {
        $this->registerAndLogin();
        $this->pdo->exec('DROP TABLE audit_logs');                         // força um erro de banco no meio de uma rota
        try {
            $r = $this->call('GET', '/api/audit');
        } finally {
            $this->pdo->exec('CREATE TABLE audit_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL,
                action VARCHAR(64) NOT NULL, entity_type VARCHAR(40) NULL, entity_id BIGINT UNSIGNED NULL, ip VARCHAR(45) NULL, meta TEXT NULL,
                created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY ix_audit_tenant (tenant_id, created_at), KEY ix_audit_action (action, created_at)) ENGINE=InnoDB');
        }
        $this->assertSame(500, $r['status']);
        $this->assertSame('Algo deu errado. Tente de novo em instantes.', $r['json']['error']);
        $this->assertStringNotContainsString('audit_logs', json_encode($r['json']));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($r['json']));
    }

    public function testOabRoutesEndToEndWithAlertInDashboardAndPlan(): void
    {
        $this->registerAndLogin();
        $r = $this->call('POST', '/api/oabs', ['oab_number' => '12.345', 'oab_uf' => 'MG', 'lawyer_name' => 'Dra. Silva', 'supplementary_ufs' => ['RJ']]);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        $id = $r['json']['data']['id'];
        $this->assertSame(['RJ'], $r['json']['data']['supplementary_ufs']);
        $this->assertSame(422, $this->call('POST', '/api/oabs', ['oab_number' => 'x', 'oab_uf' => 'MG', 'lawyer_name' => 'A'])['status']);

        // 6 processos em SP vindos da consulta do worker → alerta
        $src = new Support\FakeOabSource();
        $pubs = [];
        for ($i = 1; $i <= 6; $i++) {
            $pubs[] = Support\FakeOabSource::pub("p$i", Support\FakeOabSource::cnj($i, 2026, 8, '26'), '2026-02-01');
        }
        $src->then($pubs);
        (new \Radar\Monitoring\OabMonitor($this->pdo, $src, $this->clock))->runDue();

        $this->assertSame(1, $this->call('GET', '/api/dashboard')['json']['data']['oab_alerts_unseen']);
        $stats = $this->call('GET', '/api/oab-stats', [], ['year' => '2026'])['json']['data'];
        $this->assertSame(6, $stats['totals']['total']);
        $this->assertCount(1, $stats['alerts']);
        $this->assertSame(1, $this->call('POST', '/api/oab-alerts/' . $stats['alerts'][0]['id'] . '/seen')['json']['ok'] ? 1 : 0);
        $this->assertSame(0, $this->call('GET', '/api/dashboard')['json']['data']['oab_alerts_unseen']);

        $list = $this->call('GET', '/api/publications', [], ['uf' => 'SP', 'q' => 'Intima'])['json']['data'];
        $this->assertSame(6, $list['total']);
        $mon = $this->call('POST', '/api/publications/' . $list['items'][0]['id'] . '/monitor');
        $this->assertSame(201, $mon['status'], json_encode($mon['json']));
        $this->assertSame(1, $this->call('GET', '/api/plan')['json']['data']['oabs_used']);

        $this->assertSame(200, $this->call('POST', "/api/oabs/$id/pause")['status']);
        $this->assertSame('paused', $this->call('GET', '/api/oabs')['json']['data'][0]['status']);
        $this->assertSame(200, $this->call('PATCH', "/api/oabs/$id", ['supplementary_ufs' => ['SP']])['status']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM oab_alerts'));
    }

    public function testAssistantCannotSeeOabs(): void
    {
        $this->registerAndLogin();
        $tenantId = (int) $this->scalar('SELECT id FROM tenants');
        $this->member($tenantId, 'ass@silva.com', 'assistant');
        $this->newClient('10.0.0.9');
        $this->call('GET', '/api/session');
        $this->assertSame(200, $this->call('POST', '/api/login', ['email' => 'ass@silva.com', 'password' => 'senha-segura-123'])['status']);
        foreach (['/api/oabs', '/api/publications', '/api/oab-stats', '/api/admin/overview', '/api/admin/tenants'] as $path) {
            $this->assertSame(403, $this->call('GET', $path)['status'], $path);
        }
        $this->assertSame(0, $this->call('GET', '/api/dashboard')['json']['data']['oab_alerts_unseen']);
    }

    public function testPlatformAdminRoutesAreOnlyForPlatformAdmins(): void
    {
        $this->registerAndLogin('Escritório Comum', 'comum@x.com');
        $this->assertFalse($this->call('GET', '/api/session')['json']['data']['platform_admin']);
        foreach (['/api/admin/overview', '/api/admin/tenants', '/api/admin/plans', '/api/admin/tenants/1'] as $path) {
            $this->assertSame(403, $this->call('GET', $path)['status'], $path);
        }
        $this->assertSame(403, $this->call('POST', '/api/admin/tenants/1/renew', ['days' => 30])['status']);
        $this->assertSame('trialing', $this->scalar('SELECT status FROM subscriptions LIMIT 1'));   // nada mudou
    }

    public function testPlatformAdminCanOperateOfficesThroughTheApi(): void
    {
        $this->registerAndLogin('Cliente Um', 'cliente@x.com');
        $tenantId = (int) $this->scalar('SELECT id FROM tenants');
        $this->newClient('10.0.0.50');
        $this->registerAndLogin('Dono do SaaS', 'dono@x.com');
        $this->pdo->exec("UPDATE users SET is_platform_admin = 1 WHERE email = 'dono@x.com'");
        $this->assertTrue($this->call('GET', '/api/session')['json']['data']['platform_admin']);

        $o = $this->call('GET', '/api/admin/overview')['json']['data'];
        $this->assertSame(2, $o['tenants']);
        $list = $this->call('GET', '/api/admin/tenants', [], ['q' => 'Cliente'])['json']['data'];
        $this->assertSame(1, $list['total']);
        $r = $this->call('POST', "/api/admin/tenants/$tenantId/renew", ['days' => 30]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertSame('active', $r['json']['data']['tenant']['subscription_status']);
        $this->assertSame(200, $this->call('POST', "/api/admin/tenants/$tenantId/plan", ['plan' => 'equipe'])['status']);
        $this->assertSame(422, $this->call('POST', "/api/admin/tenants/$tenantId/plan", ['plan' => 'nao-existe'])['status']);
        $this->assertSame(200, $this->call('POST', "/api/admin/tenants/$tenantId/access", ['status' => 'suspended'])['status']);
        $this->assertSame(404, $this->call('GET', '/api/admin/tenants/99999')['status']);
        $this->assertSame(200, $this->call('GET', '/api/admin/plans')['status']);
    }

    public function testPlanEndpointCarriesTheSubscriptionBanner(): void
    {
        $this->registerAndLogin();
        $plan = $this->call('GET', '/api/plan')['json']['data'];
        $this->assertSame('info', $plan['banner']['level']);
        $this->assertSame(14, $plan['banner']['days_left']);
    }

    public function testAccountRoutesWorkOnTheLoggedUserOnly(): void
    {
        $this->registerAndLogin();
        $this->assertSame(401, (function () { $this->call('POST', '/api/logout'); return $this->call('GET', '/api/account')['status']; })());
        $this->call('GET', '/api/session');
        $this->call('POST', '/api/login', ['email' => 'dra@silva.com', 'password' => 'senha-segura-123']);
        $acc = $this->call('PATCH', '/api/account', ['name' => 'Helena', 'email_notifications' => false, 'user_id' => 999]);
        $this->assertSame('Helena', $acc['json']['data']['name']);
        $this->assertFalse($acc['json']['data']['email_notifications']);
        $this->assertSame('Helena', $this->call('GET', '/api/session')['json']['data']['user']['name']);
        $this->assertSame(422, $this->call('POST', '/api/account/password', ['current' => 'errada', 'new' => 'nova-senha-456'])['status']);
        $this->assertSame(200, $this->call('POST', '/api/account/password', ['current' => 'senha-segura-123', 'new' => 'nova-senha-456'])['status']);
    }

    public function testImportAndExportRoutes(): void
    {
        $this->registerAndLogin();
        $r = $this->call('POST', '/api/processes/import', ['csv' => "Processo;Cliente\n" . self::CNJ_TRT8 . ";Maria\nlixo;x\n"]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertSame(1, $r['json']['data']['created']);
        $this->assertSame(1, count($r['json']['data']['skipped']));
        $raw = $this->raw('GET', '/api/export/processes');
        $this->assertSame(200, $raw->status);
        $this->assertStringStartsWith('text/csv', $raw->headers['Content-Type']);
        $this->assertStringContainsString('attachment', $raw->headers['Content-Disposition']);
        $this->assertStringContainsString('Maria', $raw->body);
        $this->assertSame(200, $this->raw('GET', '/api/export/publications')->status);
    }

    public function testTermsAcceptanceIsRequiredAndRecorded(): void
    {
        $this->call('GET', '/api/session');
        $body = ['office_name' => 'Sem Aceite', 'name' => 'N', 'email' => 'n@x.com', 'password' => 'senha-segura-123'];
        $this->assertSame(422, $this->call('POST', '/api/register', $body)['status']);
        $this->assertSame(422, $this->call('POST', '/api/register', $body + ['accept_terms' => 'sim'])['status']);   // só o booleano true vale
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM users'));
        $this->assertSame(201, $this->call('POST', '/api/register', $body + ['accept_terms' => true])['status']);
        $this->assertNotNull($this->scalar('SELECT terms_accepted_at FROM users'));
        $this->assertSame('2026-10-rascunho', $this->scalar('SELECT terms_version FROM users'));
        $this->assertSame('2026-10-rascunho', $this->call('GET', '/api/account')['json']['data']['terms_version']);
    }

    public function testPrivacyRoutesExportAndErase(): void
    {
        $this->registerAndLogin();
        $raw = $this->raw('GET', '/api/privacy/export');
        $this->assertSame(200, $raw->status);
        $this->assertStringContainsString('attachment', $raw->headers['Content-Disposition']);
        $this->assertSame('dra@silva.com', json_decode($raw->body, true)['people'][0]['email']);
        $this->assertSame(422, $this->call('POST', '/api/privacy/erase', ['confirm_name' => 'Outro', 'password' => 'senha-segura-123'])['status']);
        $this->assertSame(200, $this->call('POST', '/api/privacy/erase', ['confirm_name' => 'Silva Advocacia', 'password' => 'senha-segura-123'])['status']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tenants'));
        $this->assertSame(401, $this->call('GET', '/api/dashboard')['status']);
    }

    // ── consulta avulsa por número ───────────────────────────────────────────

    private function withLookup(): void
    {
        $this->app = new App($this->pdo, $this->session, $this->mailer, 'https://radar.exemplo.com.br', $this->clock,
            lookup: new \Radar\Service\LookupService($this->pdo, null, null));
    }

    public function testLookupNeedsLoginAndValidatesTheNumber(): void
    {
        $this->withLookup();
        $this->assertSame(401, $this->call('GET', '/api/lookup', [], ['cnj' => self::CNJ_TRT8])['status']);
        $this->registerAndLogin();
        $this->withLookup();
        $this->call('POST', '/api/tenant/select', ['tenant_id' => (int) $this->scalar('SELECT id FROM tenants LIMIT 1')]);
        $this->assertSame(422, $this->call('GET', '/api/lookup', [], ['cnj' => '123'])['status']);
        $ok = $this->call('GET', '/api/lookup', [], ['cnj' => self::CNJ_TRT8]);
        $this->assertSame(200, $ok['status'], json_encode($ok['json']));
        $this->assertSame('0001457-85.2026.5.08.0106', $ok['json']['data']['cnj']);
        $this->assertSame(['not_configured', 'not_configured'], array_column($ok['json']['data']['sources'], 'status'));
    }

    public function testLookupIsThrottledPerPerson(): void
    {
        $this->registerAndLogin();
        $this->withLookup();
        $this->call('POST', '/api/tenant/select', ['tenant_id' => (int) $this->scalar('SELECT id FROM tenants LIMIT 1')]);
        for ($i = 0; $i < 15; $i++) {
            $this->assertSame(200, $this->call('GET', '/api/lookup', [], ['cnj' => self::CNJ_TRT8])['status']);
        }
        $this->assertSame(409, $this->call('GET', '/api/lookup', [], ['cnj' => self::CNJ_TRT8])['status']);
    }

    public function testFeedAndActionEndpointsFollowCsrfAndTenant(): void
    {
        $this->registerAndLogin();
        $tenant = (int) $this->scalar('SELECT id FROM tenants LIMIT 1');
        $this->call('POST', '/api/tenant/select', ['tenant_id' => $tenant]);
        $p = $this->call('POST', '/api/processes', ['cnj' => self::CNJ_TRT8]);
        $this->assertSame(201, $p['status']);
        $pid = (int) $p['json']['data']['id'];
        $source = (int) $this->scalar("SELECT id FROM integration_sources WHERE code = 'djen'");
        $this->pdo->prepare("INSERT INTO process_movements (tenant_id, process_id, source_id, external_id, occurred_at, captured_at, kind, description, is_baseline) VALUES (?, ?, ?, 'djen-9', '2026-10-09', '2026-10-09 08:00:00', 'Intimação', 'Prazo de 5 dias.', 0)")
            ->execute([$tenant, $pid, $source]);
        $mid = (int) $this->pdo->lastInsertId();

        $feed = $this->call('GET', '/api/feed', [], ['days' => '365']);
        $this->assertSame(200, $feed['status']);
        $this->assertCount(1, $feed['json']['data']);
        $this->assertSame('open', $feed['json']['data'][0]['action_status']);

        $this->assertSame(403, $this->call('POST', "/api/movements/$mid/action", ['status' => 'done'], [], ['content-type' => 'application/json'])['status']);   // sem CSRF
        $this->assertSame(422, $this->call('POST', "/api/movements/$mid/action", ['status' => 'qualquer'])['status']);
        $this->assertSame(200, $this->call('POST', "/api/movements/$mid/action", ['status' => 'done', 'note' => 'ok'])['status']);
        $this->assertSame('done', $this->call('GET', '/api/feed', [], ['days' => '365'])['json']['data'][0]['action_status']);

        $this->assertSame(200, $this->call('PATCH', "/api/processes/$pid", ['client_side' => 'A'])['status']);
        $this->assertSame('A', $this->call('GET', '/api/feed', [], ['days' => '365'])['json']['data'][0]['client_side']);
        $this->assertSame(422, $this->call('PATCH', "/api/processes/$pid", ['client_side' => 'Z'])['status']);
    }

    public function testApiSalesPanelIsAdminOnlyAndKeysWorkOnV1(): void
    {
        $this->registerAndLogin();
        $lookup = new \Radar\Service\LookupService($this->pdo, null, null);
        $this->app = new App($this->pdo, $this->session, $this->mailer, 'https://radar.exemplo.com.br', $this->clock, lookup: $lookup,
            publicApi: new \Radar\Api\PublicApi($this->pdo, $lookup, null, $this->clock));
        $plan = (int) $this->scalar("SELECT id FROM api_plans WHERE code = 'starter'");

        $this->assertSame(403, $this->call('GET', '/api/admin/api/clients')['status']);                        // dono comum não entra
        $this->pdo->exec('UPDATE users SET is_platform_admin = 1');
        $this->assertSame(200, $this->call('GET', '/api/admin/api/clients')['status']);
        $this->assertSame(403, $this->call('POST', '/api/admin/api/clients', ['name' => 'Cli', 'email' => 'c@c.com', 'plan_id' => $plan], [], ['content-type' => 'application/json'])['status']);   // sem CSRF
        $created = $this->call('POST', '/api/admin/api/clients', ['name' => 'Cli', 'email' => 'c@c.com', 'plan_id' => $plan]);
        $this->assertSame(201, $created['status'], json_encode($created['json']));
        $cid = (int) $created['json']['data']['client']['id'];
        $key = $this->call('POST', "/api/admin/api/clients/$cid/keys", ['label' => 'Produção', 'expires_in_days' => 30]);
        $this->assertSame(201, $key['status']);
        $token = $key['json']['data']['token'];
        $this->assertStringStartsWith('rdr_live_', $token);
        $detail = $this->call('GET', "/api/admin/api/clients/$cid");
        $this->assertStringNotContainsString($token, json_encode($detail['json']));                           // depois de criada, o token não volta mais

        $uso = $this->app->handle(new Request('GET', '/v1/uso', [], [], ['authorization' => 'Bearer ' . $token], '198.51.100.7', true));
        $this->assertSame(200, $uso->status);
        $this->assertSame(2000, json_decode($uso->body, true)['cota_mensal']);
        $this->assertSame(['X-Content-Type-Options', 'Cache-Control'], array_values(array_intersect(['X-Content-Type-Options', 'Cache-Control'], array_keys($uso->headers))));

        $this->assertSame(200, $this->call('POST', '/api/admin/api/keys/' . $key['json']['data']['key']['id'] . '/revoke')['status']);
        $this->assertSame(403, $this->app->handle(new Request('GET', '/v1/uso', [], [], ['authorization' => 'Bearer ' . $token], '198.51.100.7', true))->status);
        $csv = $this->raw('GET', '/api/admin/api/usage.csv');
        $this->assertStringContainsString('text/csv', $csv->headers['Content-Type']);
        $this->assertSame(401, $this->app->handle(new Request('GET', '/v1/uso', [], [], [], '1.1.1.1', true))->status);
    }
}
