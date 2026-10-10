<?php
declare(strict_types=1);

namespace Radar\Http;

use PDO;
use Radar\Auth\AuthService;
use Radar\Exception\AuthError;
use Radar\Exception\Forbidden;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Mail\Mailer;
use Radar\Mail\MailerException;
use Radar\Repository\NotificationRepository;
use Radar\Repository\ProcessRepository;
use Radar\Service\DashboardService;
use Radar\Service\MemberService;
use Radar\Service\PlanService;
use Radar\Service\ProcessService;
use Radar\Support\Clock;
use Radar\Tenancy\TenantContext;
use Throwable;

/**
 * API REST. Pontos de segurança:
 *  - o escritório vem SEMPRE da sessão; nenhum campo do pedido escolhe o escritório;
 *  - a cada pedido o vínculo usuário × escritório é conferido de novo no banco;
 *  - pedidos que alteram dados exigem JSON, o token CSRF e, se houver Origin, que seja o do sistema;
 *  - erros inesperados viram uma mensagem genérica (o detalhe vai para o log do servidor).
 */
final class App
{
    private const IDLE_SECONDS = 7200;       // 2 h sem uso
    private const ABSOLUTE_SECONDS = 43200;  // 12 h no total

    private AuthService $auth;
    private ProcessService $processes;
    private MemberService $members;
    private PlanService $plans;
    private DashboardService $dashboard;

    public function __construct(
        private PDO $pdo,
        private Session $session,
        private Mailer $mailer,
        private string $appUrl = '',
        private Clock $clock = new Clock(),
        int $maxFailures = 5,
        int $lockMinutes = 15,
    ) {
        $this->auth = new AuthService($pdo, $clock, $maxFailures, $lockMinutes);
        $this->processes = new ProcessService($pdo, $clock);
        $this->members = new MemberService($pdo, $clock);
        $this->plans = new PlanService($pdo);
        $this->dashboard = new DashboardService($pdo, $clock);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (ValidationError $e) {
            $response = Response::error($e->getMessage(), 422, 'validation');
        } catch (AuthError $e) {
            $response = Response::error($e->getMessage(), 401, 'auth');
        } catch (Forbidden $e) {
            $response = Response::error($e->getMessage(), 403, 'forbidden');
        } catch (NotFound $e) {
            $response = Response::error($e->getMessage(), 404, 'not_found');
        } catch (LimitExceeded $e) {
            $response = Response::error($e->getMessage(), 409, 'limit');
        } catch (Throwable $e) {
            error_log('[radar] ' . $e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            $response = Response::error('Algo deu errado. Tente de novo em instantes.', 500, 'server');
        }
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'",
        ];
        return $response;
    }

    private function dispatch(Request $r): Response
    {
        if (!str_starts_with($r->path, '/api/')) {
            return Response::error('Endereço desconhecido.', 404, 'not_found');
        }
        $this->expireSession();
        $method = $r->method;
        $path = rtrim($r->path, '/');

        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->assertSafeWrite($r);
        }

        // ── sem login ─────────────────────────────────────────────────────────
        if ($method === 'GET' && $path === '/api/session') {
            return $this->sessionInfo();
        }
        if ($method === 'POST' && $path === '/api/register') {
            return $this->register($r);
        }
        if ($method === 'POST' && $path === '/api/login') {
            return $this->login($r);
        }
        if ($method === 'POST' && $path === '/api/password/forgot') {
            return $this->forgotPassword($r);
        }
        if ($method === 'POST' && $path === '/api/password/reset') {
            $this->auth->resetPassword($r->str('token'), $r->str('password'), $r->ip);
            return Response::json(['ok' => true]);
        }
        if ($method === 'POST' && $path === '/api/logout') {
            $this->session->destroy();                       // a próxima chamada a /api/session cria sessão e token novos
            return Response::json(['ok' => true]);
        }

        // ── com login ─────────────────────────────────────────────────────────
        $userId = (int) $this->session->get('user_id', 0);
        if ($userId === 0) {
            throw new AuthError('Entre para continuar.');
        }
        if ($method === 'POST' && $path === '/api/tenant/select') {
            return $this->selectTenant($r, $userId);
        }
        if ($method === 'POST' && $path === '/api/invitations/accept') {
            $tenantId = (int) ($r->body['tenant_id'] ?? 0);
            $this->members->acceptInvitation($userId, $tenantId, $r->ip);   // o id do convidado vem da sessão
            return Response::json(['ok' => true]);
        }
        $ctx = $this->context($userId);

        // processos
        if ($method === 'GET' && $path === '/api/dashboard') {
            return Response::json(['ok' => true, 'data' => $this->dashboard->summary($ctx)]);
        }
        if ($method === 'GET' && $path === '/api/processes') {
            $ctx->require('process.view');
            $result = (new ProcessRepository($this->pdo, $ctx))->search([
                'q' => $r->strQuery('q'), 'status' => $r->strQuery('status'), 'tribunal' => $r->strQuery('tribunal'),
                'responsible_user_id' => $r->intQuery('responsible'), 'stale_days' => $r->intQuery('stale_days'),
                'recent_news_days' => $r->intQuery('recent_news_days'), 'with_error' => $r->strQuery('with_error') === '1',
            ], max(1, $r->intQuery('page', 1)), $r->intQuery('per_page', 25), $this->clock->now());
            return Response::json(['ok' => true, 'data' => $result]);
        }
        if ($method === 'POST' && $path === '/api/processes') {
            $p = $this->processes->create($ctx, $r->str('cnj'), $r->strOrNull('client_label'),
                isset($r->body['responsible_user_id']) ? (int) $r->body['responsible_user_id'] : null,
                $r->strOrNull('notes'), $r->ip);
            return Response::json(['ok' => true, 'data' => $p], 201);
        }
        if (preg_match('~^/api/processes/(\d+)$~', $path, $m) === 1) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                return Response::json(['ok' => true, 'data' => $this->processes->get($ctx, $id)]);
            }
            if ($method === 'PATCH') {
                $changes = [];
                foreach (['client_label', 'notes'] as $field) {
                    if (array_key_exists($field, $r->body)) {
                        $changes[$field] = is_string($r->body[$field]) ? $r->body[$field] : null;
                    }
                }
                if (array_key_exists('responsible_user_id', $r->body)) {
                    $changes['responsible_user_id'] = $r->body['responsible_user_id'] === null ? null : (int) $r->body['responsible_user_id'];
                }
                $this->processes->update($ctx, $id, $changes, $r->ip);
                return Response::json(['ok' => true, 'data' => $this->processes->get($ctx, $id)['process']]);
            }
        }
        if ($method === 'POST' && preg_match('~^/api/processes/(\d+)/(pause|resume|archive|check)$~', $path, $m) === 1) {
            $id = (int) $m[1];
            match ($m[2]) {
                'pause' => $this->processes->pause($ctx, $id, $r->ip),
                'resume' => $this->processes->resume($ctx, $id, $r->ip),
                'archive' => $this->processes->archive($ctx, $id, $r->ip),
                'check' => $this->processes->requestCheck($ctx, $id),
            };
            return Response::json(['ok' => true]);
        }

        // notificações
        if ($method === 'GET' && $path === '/api/notifications') {
            $ctx->require('notifications.view');
            $repo = new NotificationRepository($this->pdo, $ctx);
            return Response::json(['ok' => true, 'data' => ['items' => $repo->mine($r->strQuery('unread') === '1'), 'unread' => $repo->unreadCount()]]);
        }
        if ($method === 'POST' && preg_match('~^/api/notifications/(\d+)/read$~', $path, $m) === 1) {
            $ctx->require('notifications.view');
            return Response::json(['ok' => (new NotificationRepository($this->pdo, $ctx))->markRead((int) $m[1], $this->clock->now())]);
        }

        // equipe
        if ($method === 'GET' && $path === '/api/team') {
            return Response::json(['ok' => true, 'data' => $this->members->list($ctx)]);
        }
        if ($method === 'POST' && $path === '/api/team/invite') {
            return $this->invite($ctx, $r);
        }
        if ($method === 'POST' && preg_match('~^/api/team/(\d+)/(disable|role)$~', $path, $m) === 1) {
            if ($m[2] === 'disable') {
                $this->members->disable($ctx, (int) $m[1], $r->ip);
            } else {
                $this->members->changeRole($ctx, (int) $m[1], $r->str('role'), $r->ip);
            }
            return Response::json(['ok' => true]);
        }

        // plano e auditoria
        if ($method === 'GET' && $path === '/api/plan') {
            $ctx->require('plan.view');
            $plan = $this->plans->forTenant($ctx->tenantId);
            $plan['processes_used'] = (new ProcessRepository($this->pdo, $ctx))->countNotArchived();
            $plan['users_used'] = count(array_filter($this->members->list($ctx), static fn (array $m) => $m['status'] !== 'disabled'));
            return Response::json(['ok' => true, 'data' => $plan]);
        }
        if ($method === 'GET' && $path === '/api/audit') {
            $ctx->require('audit.view');
            $stmt = $this->pdo->prepare('SELECT a.action, a.entity_type, a.entity_id, a.ip, a.created_at, u.name AS user_name
                FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 100');
            $stmt->execute([$ctx->tenantId]);
            return Response::json(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        return Response::error('Endereço desconhecido.', 404, 'not_found');
    }

    // ── sessão e segurança ───────────────────────────────────────────────────

    private function expireSession(): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->session->get('user_id') !== null) {
            $last = (int) $this->session->get('last_seen', $now);
            $created = (int) $this->session->get('created', $now);
            if ($now - $last > self::IDLE_SECONDS || $now - $created > self::ABSOLUTE_SECONDS) {
                $this->session->destroy();
                return;
            }
            $this->session->set('last_seen', $now);
        }
    }

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }
        return $token;
    }

    private function assertSafeWrite(Request $r): void
    {
        if (str_contains(strtolower($r->header('content-type')), 'application/json') === false) {
            throw new Forbidden('Pedido não permitido.');
        }
        $origin = $r->header('origin');
        if ($origin !== '' && $this->appUrl !== '') {
            $allowed = rtrim($this->appUrl, '/');
            if (strcasecmp(rtrim($origin, '/'), $allowed) !== 0) {
                throw new Forbidden('Pedido não permitido.');
            }
        }
        $sent = $r->header('x-csrf-token');
        if ($sent === '' || !hash_equals($this->csrfToken(), $sent)) {
            throw new Forbidden('Sessão expirada. Recarregue a página.');
        }
        if (isset($r->body['__invalid'])) {
            throw new ValidationError('Pedido malformado.');
        }
    }

    /** O escritório ativo vem da sessão e é conferido no banco a cada pedido. */
    private function context(int $userId): TenantContext
    {
        $tenantId = (int) $this->session->get('tenant_id', 0);
        if ($tenantId === 0) {
            throw new Forbidden('Escolha um escritório.');
        }
        try {
            return TenantContext::resolve($this->pdo, $userId, $tenantId);
        } catch (Forbidden $e) {
            $this->session->set('tenant_id', null);      // perdeu o acesso (desativado, escritório suspenso…)
            throw $e;
        }
    }

    private function sessionInfo(): Response
    {
        $data = ['authenticated' => false, 'csrf' => $this->csrfToken()];
        $userId = (int) $this->session->get('user_id', 0);
        if ($userId !== 0) {
            $data['authenticated'] = true;
            $data['user'] = ['id' => $userId, 'name' => (string) $this->session->get('user_name', '')];
            $data['tenants'] = $this->session->get('tenants', []);
            $tenantId = (int) $this->session->get('tenant_id', 0);
            if ($tenantId !== 0) {
                try {
                    $ctx = TenantContext::resolve($this->pdo, $userId, $tenantId);
                    $data['tenant'] = ['id' => $tenantId, 'role' => $ctx->role->value, 'permissions' => $ctx->role->permissions()];
                } catch (Forbidden) {
                    $this->session->set('tenant_id', null);
                }
            }
        }
        return Response::json(['ok' => true, 'data' => $data]);
    }

    // ── ações sem login ou de entrada ────────────────────────────────────────

    /** Limite por IP, contado no registro de auditoria (que já guarda ação, IP e hora). */
    private function tooManyFromIp(string $ip, string $action, int $max, int $minutes): bool
    {
        if ($ip === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND ip = ? AND created_at >= ?');
        $stmt->execute([$action, $ip, Clock::sql($this->clock->now()->modify("-$minutes minutes"))]);
        return (int) $stmt->fetchColumn() >= $max;
    }

    private function register(Request $r): Response
    {
        if ($this->tooManyFromIp($r->ip, 'tenant.registered', 5, 60)) {
            throw new LimitExceeded('Muitos cadastros a partir desta conexão. Tente mais tarde.');
        }
        $ids = $this->auth->registerTenant($r->str('office_name'), $r->str('name'), $r->str('email'), $r->str('password'),
            'essencial', $r->strOrNull('phone'), $r->ip);
        $this->startSession($this->auth->login($r->str('email'), $r->str('password'), $r->ip), $ids['tenant_id']);
        return Response::json(['ok' => true, 'data' => ['tenant_id' => $ids['tenant_id']]], 201);
    }

    private function login(Request $r): Response
    {
        if ($this->tooManyFromIp($r->ip, 'auth.login_failed', 20, 15)) {
            throw new AuthError('Muitas tentativas. Aguarde alguns minutos e tente de novo.');
        }
        $login = $this->auth->login($r->str('email'), $r->str('password'), $r->ip);
        $this->startSession($login, count($login['tenants']) === 1 ? $login['tenants'][0]['tenant_id'] : null);
        return Response::json(['ok' => true, 'data' => ['tenants' => $login['tenants'], 'selected' => $this->session->get('tenant_id')]]);
    }

    /** @param array<string,mixed> $login */
    private function startSession(array $login, ?int $tenantId): void
    {
        $this->session->regenerate();                      // novo identificador a cada login (contra fixação de sessão)
        $now = $this->clock->now()->getTimestamp();
        $this->session->set('user_id', $login['user_id']);
        $this->session->set('user_name', $login['name']);
        $this->session->set('tenants', $login['tenants']);
        $this->session->set('tenant_id', $tenantId);
        $this->session->set('created', $now);
        $this->session->set('last_seen', $now);
        $this->session->set('csrf', bin2hex(random_bytes(32)));
    }

    private function selectTenant(Request $r, int $userId): Response
    {
        $tenantId = (int) ($r->body['tenant_id'] ?? 0);
        $ctx = TenantContext::resolve($this->pdo, $userId, $tenantId);       // recusa se não for integrante ativo
        $this->session->set('tenant_id', $ctx->tenantId);
        return Response::json(['ok' => true]);
    }

    private function forgotPassword(Request $r): Response
    {
        $email = AuthService::normalizeEmail($r->str('email'));
        $token = $this->auth->requestPasswordReset($email, $r->ip);
        if ($token !== null) {
            try {
                $this->mailer->send($email, '', 'Radar Trabalhista: redefinir sua senha',
                    "Recebemos um pedido para redefinir a sua senha.\n\n"
                    . rtrim($this->appUrl, '/') . '/#/redefinir?token=' . $token . "\n\n"
                    . "O link vale por 60 minutos e só pode ser usado uma vez. Se não foi você, ignore esta mensagem.\n");
            } catch (MailerException $e) {
                error_log('[radar] e-mail de redefinição não enviado: ' . $e->getMessage());
            }
        }
        // a resposta é a mesma exista ou não o e-mail: não dá para descobrir quem tem conta
        return Response::json(['ok' => true, 'message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções.']);
    }

    private function invite(TenantContext $ctx, Request $r): Response
    {
        $result = $this->members->invite($ctx, $r->str('name'), $r->str('email'), $r->str('role'), $r->ip);
        $email = AuthService::normalizeEmail($r->str('email'));
        try {
            $link = $result['invite_token'] !== null
                ? rtrim($this->appUrl, '/') . '/#/redefinir?token=' . $result['invite_token']
                : rtrim($this->appUrl, '/') . '/#/convites';
            $this->mailer->send($email, $r->str('name'), 'Radar Trabalhista: você foi convidado',
                "Você foi convidado(a) para um escritório no Radar Trabalhista.\n\n"
                . ($result['invite_token'] !== null ? "Defina a sua senha por este link (válido por 7 dias):\n" : "Entre na sua conta e aceite o convite:\n")
                . $link . "\n");
        } catch (MailerException $e) {
            error_log('[radar] convite não enviado: ' . $e->getMessage());
            return Response::json(['ok' => true, 'data' => ['user_id' => $result['user_id'], 'email_sent' => false]], 201);
        }
        return Response::json(['ok' => true, 'data' => ['user_id' => $result['user_id'], 'email_sent' => true]], 201);
    }
}
