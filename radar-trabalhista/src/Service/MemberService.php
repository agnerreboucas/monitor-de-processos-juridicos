<?php
declare(strict_types=1);

namespace Radar\Service;

use DateInterval;
use PDO;
use Radar\Auth\AuthService;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Tenancy\Role;
use Radar\Tenancy\TenantContext;

/** Equipe do escritório: convites, papéis e acessos. */
final class MemberService
{
    private Audit $audit;
    private PlanService $plans;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
        $this->plans = new PlanService($pdo);
    }

    /** @return list<array{user_id:int,name:string,email:string,role:string,status:string}> */
    public function list(TenantContext $ctx): array
    {
        $ctx->require('process.view');
        $stmt = $this->pdo->prepare(
            'SELECT u.id AS user_id, u.name, u.email, m.role, m.status FROM tenant_members m
               JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY u.name'
        );
        $stmt->execute([$ctx->tenantId]);
        return array_map(static fn (array $r) => [
            'user_id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'],
            'role' => (string) $r['role'], 'status' => (string) $r['status'],
        ], $stmt->fetchAll());
    }

    /**
     * Convida alguém. Para quem ainda não tem conta, devolve um token de definição de senha
     * (o link do convite). Quem já tem conta em outro escritório recebe um convite pendente
     * e precisa aceitar: ninguém é colocado num escritório sem saber.
     *
     * @return array{user_id:int,invite_token:?string,pending_acceptance:bool}
     */
    public function invite(TenantContext $ctx, string $name, string $email, string $role, ?string $ip = null): array
    {
        $ctx->require('members.manage');
        $email = AuthService::normalizeEmail($email);
        AuthService::assertEmail($email);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new ValidationError('Informe o nome.');
        }
        $roleEnum = Role::tryFrom($role) ?? throw new ValidationError('Papel inválido.');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE')->execute([$ctx->tenantId]);
            $existing = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
            $existing->execute([$email]);
            $userId = $existing->fetchColumn();
            if ($userId !== false) {
                $already = $this->pdo->prepare('SELECT 1 FROM tenant_members WHERE tenant_id = ? AND user_id = ?');
                $already->execute([$ctx->tenantId, (int) $userId]);
                if ($already->fetchColumn() !== false) {
                    throw new ValidationError('Esta pessoa já faz parte do escritório.');
                }
            }
            $plan = $this->plans->forTenant($ctx->tenantId);
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM tenant_members WHERE tenant_id = ? AND status IN ('active','invited')");
            $count->execute([$ctx->tenantId]);
            if ((int) $count->fetchColumn() >= $plan['max_users']) {
                throw new LimitExceeded('Limite de ' . $plan['max_users'] . ' usuários do plano ' . $plan['plan_name'] . ' atingido.');
            }
            $now = Clock::sql($this->clock->now());
            $token = null;
            $pending = true;
            if ($userId === false) {
                $this->pdo->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $email, AuthService::hashPassword(bin2hex(random_bytes(24))), $now]);
                $userId = (int) $this->pdo->lastInsertId();
                $token = bin2hex(random_bytes(32));
                $this->pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, hash('sha256', $token),
                        Clock::sql($this->clock->now()->add(new DateInterval('P7D'))), $now]);
                $pending = false;                      // conta nova: entra ativa, só falta definir a senha
            }
            $userId = (int) $userId;
            $this->pdo->prepare('INSERT INTO tenant_members (tenant_id, user_id, role, status, invited_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$ctx->tenantId, $userId, $roleEnum->value, $pending ? 'invited' : 'active', $ctx->userId, $now]);
            $this->audit->log('member.invited', $ctx->tenantId, $ctx->userId, 'user', $userId, $ip, ['role' => $roleEnum->value]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['user_id' => $userId, 'invite_token' => $token, 'pending_acceptance' => $pending];
    }

    /** O próprio convidado aceita (usa o id dele da sessão, não um dado do formulário). */
    public function acceptInvitation(int $userId, int $tenantId, ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare("UPDATE tenant_members SET status = 'active' WHERE tenant_id = ? AND user_id = ? AND status = 'invited'");
        $stmt->execute([$tenantId, $userId]);
        if ($stmt->rowCount() === 0) {
            throw new NotFound('Convite não encontrado.');
        }
        $this->audit->log('member.accepted', $tenantId, $userId, 'user', $userId, $ip);
    }

    public function changeRole(TenantContext $ctx, int $userId, string $role, ?string $ip = null): void
    {
        $ctx->require('members.manage');
        $roleEnum = Role::tryFrom($role) ?? throw new ValidationError('Papel inválido.');
        $this->guardLastAdmin($ctx, $userId, $roleEnum !== Role::OfficeAdmin);
        $stmt = $this->pdo->prepare('UPDATE tenant_members SET role = ? WHERE tenant_id = ? AND user_id = ?');
        $stmt->execute([$roleEnum->value, $ctx->tenantId, $userId]);
        $this->audit->log('member.role_changed', $ctx->tenantId, $ctx->userId, 'user', $userId, $ip, ['role' => $roleEnum->value]);
    }

    public function disable(TenantContext $ctx, int $userId, ?string $ip = null): void
    {
        $ctx->require('members.manage');
        $this->guardLastAdmin($ctx, $userId, true);
        $stmt = $this->pdo->prepare("UPDATE tenant_members SET status = 'disabled' WHERE tenant_id = ? AND user_id = ?");
        $stmt->execute([$ctx->tenantId, $userId]);
        if ($stmt->rowCount() === 0) {
            throw new NotFound('Integrante não encontrado.');
        }
        $this->audit->log('member.disabled', $ctx->tenantId, $ctx->userId, 'user', $userId, $ip);
    }

    /** O escritório nunca pode ficar sem administrador ativo. */
    private function guardLastAdmin(TenantContext $ctx, int $userId, bool $removingAdmin): void
    {
        if (!$removingAdmin) {
            return;
        }
        $stmt = $this->pdo->prepare("SELECT user_id FROM tenant_members WHERE tenant_id = ? AND role = 'office_admin' AND status = 'active'");
        $stmt->execute([$ctx->tenantId]);
        $admins = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (in_array($userId, $admins, true) && count($admins) <= 1) {
            throw new ValidationError('O escritório precisa ter ao menos um administrador ativo.');
        }
    }
}
