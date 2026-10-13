<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Mail\Mailer;
use Radar\Mail\MailerException;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Tenancy\TenantContext;

/**
 * Contratação sem gateway de pagamento. O administrador do escritório pede um plano; o dono do SaaS recebe um aviso
 * (painel e e-mail), combina o pagamento por fora (Pix, boleto…) e libera pela tela da administração.
 */
final class SalesService
{
    private Audit $audit;

    public function __construct(private PDO $pdo, private Mailer $mailer, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
    }

    /** @return array<string,mixed>|null o pedido em aberto do escritório */
    public function openFor(TenantContext $ctx): ?array
    {
        $ctx->require('plan.view');
        $stmt = $this->pdo->prepare("SELECT id, plan_code, message, created_at FROM plan_requests WHERE tenant_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$ctx->tenantId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed> */
    public function request(TenantContext $ctx, string $planCode, ?string $message, ?string $ip = null): array
    {
        $ctx->require('members.manage');       // só o administrador do escritório pede contratação
        $plan = $this->pdo->prepare('SELECT name FROM plans WHERE code = ? AND active = 1');
        $plan->execute([$planCode]);
        $planName = $plan->fetchColumn();
        if ($planName === false) {
            throw new ValidationError('Plano inexistente.');
        }
        $message = $message !== null ? mb_substr(trim($message), 0, 500) : null;
        $this->pdo->prepare("UPDATE plan_requests SET status = 'dismissed', handled_at = ? WHERE tenant_id = ? AND status = 'open'")
            ->execute([Clock::sql($this->clock->now()), $ctx->tenantId]);        // um pedido em aberto por escritório
        $this->pdo->prepare('INSERT INTO plan_requests (tenant_id, user_id, plan_code, message, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ctx->tenantId, $ctx->userId, $planCode, $message !== '' ? $message : null, Clock::sql($this->clock->now())]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log('plan.requested', $ctx->tenantId, $ctx->userId, 'plan_request', $id, $ip, ['plan' => $planCode]);
        $this->notifyOwners($ctx, (string) $planName, $message);
        return ['id' => $id, 'plan_code' => $planCode, 'plan_name' => $planName];
    }

    private function notifyOwners(TenantContext $ctx, string $planName, ?string $message): void
    {
        $office = $this->pdo->prepare('SELECT t.name, u.name AS who, u.email FROM tenants t JOIN users u ON u.id = ? WHERE t.id = ?');
        $office->execute([$ctx->userId, $ctx->tenantId]);
        $o = $office->fetch();
        $owners = $this->pdo->query("SELECT email, name FROM users WHERE is_platform_admin = 1 AND status = 'active'")->fetchAll();
        foreach ($owners as $owner) {
            try {
                $this->mailer->send($owner['email'], (string) $owner['name'], 'Radar Processual: pedido de contratação',
                    "O escritório " . ($o['name'] ?? '') . " pediu o plano $planName.\nContato: " . ($o['who'] ?? '') . ' <' . ($o['email'] ?? '') . ">\n"
                    . ($message !== null && $message !== '' ? "Mensagem: $message\n" : '') . "\nCombine o pagamento e libere em Administração > Pedidos de contratação.");
            } catch (MailerException) {
                // o pedido já está salvo e aparece no painel; o e-mail é só um aviso extra
            }
        }
    }

    // ── dono do SaaS ────────────────────────────────────────────────────────

    private function assertAdmin(int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_platform_admin = 1 AND status = 'active'");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new Forbidden();
        }
    }

    /** @return list<array<string,mixed>> */
    public function openRequests(int $adminId): array
    {
        $this->assertAdmin($adminId);
        return $this->pdo->query(
            "SELECT r.id, r.tenant_id, t.name AS tenant_name, r.plan_code, p.name AS plan_name, r.message, r.created_at, u.name AS requester, u.email AS requester_email,
                    s.status AS subscription_status, s.current_period_end
               FROM plan_requests r JOIN tenants t ON t.id = r.tenant_id
          LEFT JOIN plans p ON p.code = r.plan_code LEFT JOIN users u ON u.id = r.user_id
          LEFT JOIN subscriptions s ON s.tenant_id = r.tenant_id
              WHERE r.status = 'open' ORDER BY r.created_at"
        )->fetchAll();
    }

    public function resolve(int $adminId, int $requestId, string $status, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        if (!in_array($status, ['done', 'dismissed'], true)) {
            throw new ValidationError('Situação inválida.');
        }
        $stmt = $this->pdo->prepare("UPDATE plan_requests SET status = ?, handled_at = ?, handled_by = ? WHERE id = ? AND status = 'open'");
        $stmt->execute([$status, Clock::sql($this->clock->now()), $adminId, $requestId]);
        if ($stmt->rowCount() === 0) {
            throw new NotFound('Pedido não encontrado ou já tratado.');
        }
        $this->audit->log('plan.request.' . $status, null, $adminId, 'plan_request', $requestId, $ip);
    }
}
