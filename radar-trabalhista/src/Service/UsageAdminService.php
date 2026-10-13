<?php
declare(strict_types=1);

namespace Radar\Service;

use DateInterval;
use PDO;
use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Support\Schedule;

/**
 * Gestão de usuários e estatísticas de uso para o dono do SaaS, do todo e de cada escritório/usuário.
 * Só mostra cadastro e números (quem, quando, quantas buscas). Nunca mostra processos, textos, senhas ou tokens.
 */
final class UsageAdminService
{
    private Audit $audit;
    private BillingService $billing;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
        $this->billing = new BillingService($pdo, $clock);
    }

    private function assertAdmin(int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_platform_admin = 1 AND status = 'active'");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new Forbidden();
        }
    }

    private function one(string $sql, array $p = []): mixed
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn();
    }

    /** @return list<string> os últimos $days dias (data de Brasília), do mais antigo ao mais novo */
    private function days(int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[] = Schedule::localDay($this->clock->now()->sub(new DateInterval("P{$i}D")));
        }
        return $out;
    }

    // ── visão geral ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function overview(int $adminId, int $days = 30): array
    {
        $this->assertAdmin($adminId);
        $days = max(7, min(180, $days));
        $list = $this->days($days);
        $from = $list[0];
        $now = $this->clock->now();

        $bySub = $this->pdo->query('SELECT status, COUNT(*) n FROM subscriptions GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $tenants = ['total' => (int) array_sum($bySub)] + array_map('intval', $bySub);
        $users = [
            'total' => (int) $this->one("SELECT COUNT(*) FROM users WHERE is_platform_admin = 0"),
            'disabled' => (int) $this->one("SELECT COUNT(*) FROM users WHERE status = 'disabled'"),
            'active_7d' => (int) $this->one('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [Clock::sql($now->modify('-7 days'))]),
            'active_30d' => (int) $this->one('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [Clock::sql($now->modify('-30 days'))]),
            'never_logged' => (int) $this->one('SELECT COUNT(*) FROM users WHERE last_login_at IS NULL AND is_platform_admin = 0'),
        ];
        $searchRows = $this->pdo->prepare('SELECT day, SUM(ok) ok, SUM(failed) failed FROM search_usage_daily WHERE day >= ? GROUP BY day');
        $searchRows->execute([$from]);
        $byDay = [];
        foreach ($searchRows->fetchAll() as $r) {
            $byDay[$r['day']] = $r;
        }
        $kinds = $this->pdo->prepare('SELECT kind, SUM(ok) ok, SUM(failed) failed FROM search_usage_daily WHERE day >= ? GROUP BY kind');
        $kinds->execute([$from]);
        $signups = $this->pdo->prepare("SELECT DATE(CONVERT_TZ(created_at, '+00:00', '-03:00')) d, COUNT(*) n FROM tenants WHERE created_at >= ? GROUP BY d");
        $signups->execute([Clock::sql($now->modify("-$days days"))]);
        $signupMap = $signups->fetchAll(PDO::FETCH_KEY_PAIR);
        $series = [];
        foreach ($list as $d) {
            $series[] = ['day' => $d, 'ok' => (int) ($byDay[$d]['ok'] ?? 0), 'failed' => (int) ($byDay[$d]['failed'] ?? 0), 'signups' => (int) ($signupMap[$d] ?? 0)];
        }
        $top = $this->pdo->prepare(
            'SELECT t.id, t.name, SUM(u.ok) ok, SUM(u.failed) failed FROM search_usage_daily u JOIN tenants t ON t.id = u.tenant_id WHERE u.day >= ? GROUP BY t.id, t.name ORDER BY ok DESC LIMIT 8'
        );
        $top->execute([$from]);
        $revenue = 0;
        foreach ($this->pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $revenue += $this->billing->statement((int) $tid)['amount_cents'];     // mês corrente
        }
        return [
            'days' => $days, 'tenants' => $tenants, 'users' => $users,
            'searches' => ['ok' => array_sum(array_column($series, 'ok')), 'failed' => array_sum(array_column($series, 'failed')), 'by_kind' => $kinds->fetchAll()],
            'series' => $series, 'top_tenants' => $top->fetchAll(), 'revenue_month_cents' => $revenue,
            'api' => ['requests_month' => (int) $this->one('SELECT COALESCE(SUM(requests), 0) FROM api_usage_daily WHERE day >= ?', [substr(Schedule::localDay($now), 0, 8) . '01'])],
        ];
    }

    // ── usuários ────────────────────────────────────────────────────────────

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function users(int $adminId, string $q = '', string $status = '', string $inactive = ''): array
    {
        $this->assertAdmin($adminId);
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR EXISTS (SELECT 1 FROM tenant_members m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = u.id AND t.name LIKE ?))';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if (in_array($status, ['active', 'disabled'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if ($inactive === '30') {
            $where[] = '(u.last_login_at IS NULL OR u.last_login_at < ?)';
            $params[] = Clock::sql($this->clock->now()->modify('-30 days'));
        }
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.status, u.is_platform_admin, u.last_login_at, u.created_at,
                    (SELECT GROUP_CONCAT(CONCAT(t.name, ' (', m.role, ')') ORDER BY t.name SEPARATOR '; ') FROM tenant_members m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = u.id AND m.status <> 'disabled') AS offices
               FROM users u WHERE " . implode(' AND ', $where) . ' ORDER BY u.created_at DESC, u.id DESC LIMIT 300'
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        return ['items' => $items, 'total' => count($items)];
    }

    /** @return array<string,mixed> */
    public function user(int $adminId, int $userId): array
    {
        $this->assertAdmin($adminId);
        $u = $this->pdo->prepare('SELECT id, name, email, status, is_platform_admin, last_login_at, created_at, terms_accepted_at, terms_version FROM users WHERE id = ?');
        $u->execute([$userId]);
        $user = $u->fetch() ?: throw new NotFound('Usuário não encontrado.');
        $m = $this->pdo->prepare('SELECT t.id, t.name, m.role, m.status FROM tenant_members m JOIN tenants t ON t.id = m.tenant_id WHERE m.user_id = ? ORDER BY t.name');
        $m->execute([$userId]);
        $since = Clock::sql($this->clock->now()->modify('-30 days'));
        $acts = $this->pdo->prepare('SELECT action, COUNT(*) n FROM audit_logs WHERE user_id = ? AND created_at >= ? GROUP BY action ORDER BY n DESC LIMIT 15');
        $acts->execute([$userId, $since]);
        $recent = $this->pdo->prepare('SELECT action, entity_type, created_at FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $recent->execute([$userId]);
        return ['user' => $user, 'offices' => $m->fetchAll(), 'actions_30d' => $acts->fetchAll(), 'recent' => $recent->fetchAll()];
    }

    public function setUserStatus(int $adminId, int $userId, string $status, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new ValidationError('Situação inválida.');
        }
        if ($userId === $adminId) {
            throw new ValidationError('Você não pode bloquear o seu próprio acesso.');
        }
        $target = $this->pdo->prepare('SELECT is_platform_admin FROM users WHERE id = ?');
        $target->execute([$userId]);
        $row = $target->fetch() ?: throw new NotFound('Usuário não encontrado.');
        if ((int) $row['is_platform_admin'] === 1) {
            throw new ValidationError('Administradores da plataforma são geridos pelo servidor (bin/criar-admin.php).');
        }
        $this->pdo->prepare('UPDATE users SET status = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$status, $userId]);
        $this->audit->log('user.' . ($status === 'disabled' ? 'blocked' : 'unblocked'), null, $adminId, 'user', $userId, $ip);
    }

    // ── um escritório ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function tenant(int $adminId, int $tenantId, int $days = 30): array
    {
        $this->assertAdmin($adminId);
        if ($this->one('SELECT COUNT(*) FROM tenants WHERE id = ?', [$tenantId]) === '0') {
            throw new NotFound('Escritório não encontrado.');
        }
        $days = max(7, min(180, $days));
        $list = $this->days($days);
        $rows = $this->pdo->prepare('SELECT day, SUM(ok) ok, SUM(failed) failed FROM search_usage_daily WHERE tenant_id = ? AND day >= ? GROUP BY day');
        $rows->execute([$tenantId, $list[0]]);
        $byDay = [];
        foreach ($rows->fetchAll() as $r) {
            $byDay[$r['day']] = $r;
        }
        $series = array_map(fn ($d) => ['day' => $d, 'ok' => (int) ($byDay[$d]['ok'] ?? 0), 'failed' => (int) ($byDay[$d]['failed'] ?? 0)], $list);
        $members = $this->pdo->prepare("SELECT u.id, u.name, u.email, m.role, m.status, u.last_login_at, (SELECT COUNT(*) FROM audit_logs a WHERE a.user_id = u.id AND a.tenant_id = m.tenant_id AND a.created_at >= ?) AS actions_30d
                                         FROM tenant_members m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY m.id");
        $members->execute([Clock::sql($this->clock->now()->modify('-30 days')), $tenantId]);
        return [
            'series' => $series, 'statement' => $this->billing->statement($tenantId),
            'counts' => ['processes' => (int) $this->one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status <> 'archived'", [$tenantId]),
                'oabs' => (int) $this->one("SELECT COUNT(*) FROM oab_registrations WHERE tenant_id = ? AND status <> 'archived'", [$tenantId]),
                'publications' => (int) $this->one('SELECT COUNT(*) FROM oab_publications WHERE tenant_id = ?', [$tenantId])],
            'members' => $members->fetchAll(), 'last_activity' => $this->one('SELECT MAX(created_at) FROM audit_logs WHERE tenant_id = ?', [$tenantId]),
        ];
    }
}
