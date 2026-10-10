<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;

/**
 * Administração do SaaS (RF-012): só quem tem is_platform_admin. Enxerga TODOS os escritórios, por isso:
 * - cada método confere a permissão no banco (nunca confia na sessão);
 * - só mostra números e dados cadastrais, NUNCA o conteúdo de processos, movimentações ou publicações;
 * - toda mudança vai para a auditoria do escritório afetado.
 */
final class PlatformService
{
    private Audit $audit;
    private SubscriptionService $subs;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
        $this->subs = new SubscriptionService($pdo, $clock);
    }

    public function assertAdmin(int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_platform_admin = 1 AND status = 'active'");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new Forbidden();
        }
    }

    /** @return array<string,mixed> */
    public function overview(int $adminId): array
    {
        $this->assertAdmin($adminId);
        $one = fn (string $sql, array $p = []) => (function () use ($sql, $p) {
            $s = $this->pdo->prepare($sql);
            $s->execute($p);
            return $s->fetchColumn();
        })();
        $since = Clock::sql($this->clock->now()->modify('-24 hours'));
        $byStatus = $this->pdo->query('SELECT status, COUNT(*) n FROM subscriptions GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $mrr = (int) $one("SELECT COALESCE(SUM(p.price_cents), 0) FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.status = 'active'");
        $run = $this->pdo->query('SELECT started_at, finished_at, summary FROM worker_runs ORDER BY id DESC LIMIT 1')->fetch();
        return [
            'tenants' => (int) $one('SELECT COUNT(*) FROM tenants'),
            'subscriptions' => array_map('intval', $byStatus),
            'users' => (int) $one("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'processes_active' => (int) $one("SELECT COUNT(*) FROM processes WHERE status = 'active'"),
            'oabs_active' => (int) $one("SELECT COUNT(*) FROM oab_registrations WHERE status = 'active'"),
            'monthly_revenue_cents' => $mrr,
            'checks_failed_24h' => (int) $one("SELECT COUNT(*) FROM monitoring_events WHERE outcome IN ('source_error','invalid_response') AND finished_at >= ?", [$since]),
            'checks_ok_24h' => (int) $one("SELECT COUNT(*) FROM monitoring_events WHERE outcome IN ('new_movements','no_news') AND finished_at >= ?", [$since]),
            'processes_with_error' => (int) $one("SELECT COUNT(*) FROM processes WHERE status = 'active' AND last_error IS NOT NULL"),
            'oabs_with_error' => (int) $one("SELECT COUNT(*) FROM oab_registrations WHERE status = 'active' AND last_error IS NOT NULL"),
            'queued_jobs' => (int) $one("SELECT COUNT(*) FROM monitoring_jobs WHERE status = 'queued'"),
            'last_worker_run' => $run === false ? null : $run,
            'worker_stale' => $run === false || $run['started_at'] < Clock::sql($this->clock->now()->modify('-20 minutes')),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function tenants(int $adminId, string $q = '', string $status = '', int $page = 1, int $perPage = 25, ?int $onlyId = null): array
    {
        $this->assertAdmin($adminId);
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(t.name LIKE :q1 OR EXISTS (SELECT 1 FROM tenant_members m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = t.id AND u.email LIKE :q2))';
            $params['q1'] = $params['q2'] = '%' . addcslashes($q, '\\%_') . '%';
        }
        if (in_array($status, ['trialing', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
            $where[] = 's.status = :st';
            $params['st'] = $status;
        }
        if ($onlyId !== null) {
            $where[] = 't.id = :only';
            $params['only'] = $onlyId;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id WHERE $sqlWhere");
        $count->execute($params);
        $perPage = max(1, min(100, $perPage));
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.name, t.status AS tenant_status, t.created_at, p.code AS plan_code, p.name AS plan_name, s.status AS subscription_status,
                    s.current_period_end, s.suspended_reason, s.admin_note,
                    (SELECT COUNT(*) FROM tenant_members m WHERE m.tenant_id = t.id AND m.status <> 'disabled') AS users,
                    (SELECT COUNT(*) FROM processes x WHERE x.tenant_id = t.id AND x.status <> 'archived') AS processes,
                    (SELECT COUNT(*) FROM oab_registrations o WHERE o.tenant_id = t.id AND o.status <> 'archived') AS oabs,
                    (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.tenant_id = t.id) AS last_activity
               FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id JOIN plans p ON p.id = s.plan_id
              WHERE $sqlWhere ORDER BY t.id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (max(1, $page) - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string,mixed> */
    public function tenant(int $adminId, int $tenantId): array
    {
        $this->assertAdmin($adminId);
        $found = $this->tenants($adminId, '', '', 1, 1, $tenantId);
        if ($found['items'] === []) {
            throw new NotFound('Escritório não encontrado.');
        }
        $row = $found['items'][0];
        $members = $this->pdo->prepare('SELECT u.name, u.email, m.role, m.status, u.last_login_at FROM tenant_members m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY m.id');
        $members->execute([$tenantId]);
        $audit = $this->pdo->prepare('SELECT action, entity_type, created_at FROM audit_logs WHERE tenant_id = ? ORDER BY id DESC LIMIT 30');
        $audit->execute([$tenantId]);
        return ['tenant' => $row, 'members' => $members->fetchAll(), 'audit' => $audit->fetchAll()];
    }

    public function setTenantStatus(int $adminId, int $tenantId, string $status, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new ValidationError('Situação inválida.');
        }
        $stmt = $this->pdo->prepare('UPDATE tenants SET status = ? WHERE id = ?');
        $stmt->execute([$status, $tenantId]);
        $exists = $this->pdo->prepare('SELECT 1 FROM tenants WHERE id = ?');
        $exists->execute([$tenantId]);
        if ($exists->fetchColumn() === false) {
            throw new NotFound('Escritório não encontrado.');
        }
        $this->audit->log('platform.tenant_' . $status, $tenantId, $adminId, 'tenant', $tenantId, $ip);
    }

    public function renew(int $adminId, int $tenantId, int $days, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        $this->subs->renew($tenantId, $days, $adminId, $ip);
    }

    public function setSubscriptionStatus(int $adminId, int $tenantId, string $status, ?string $reason, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        $this->subs->setStatus($tenantId, $status, $reason, $adminId, $ip);
    }

    public function setPlan(int $adminId, int $tenantId, string $planCode, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        $this->subs->setPlan($tenantId, $planCode, $adminId, $ip);
    }

    public function setNote(int $adminId, int $tenantId, string $note): void
    {
        $this->assertAdmin($adminId);
        $this->pdo->prepare('UPDATE subscriptions SET admin_note = ? WHERE tenant_id = ?')->execute([mb_substr(trim($note), 0, 500) ?: null, $tenantId]);
    }

    /** @return list<array<string,mixed>> */
    public function plans(int $adminId): array
    {
        $this->assertAdmin($adminId);
        return $this->pdo->query('SELECT code, name, price_cents, max_processes, max_users, max_oabs, min_interval_minutes FROM plans WHERE active = 1 ORDER BY price_cents, id')->fetchAll();
    }
}
