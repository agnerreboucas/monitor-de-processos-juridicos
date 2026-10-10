<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Repository\MovementRepository;
use Radar\Repository\NotificationRepository;
use Radar\Support\Clock;
use Radar\Tenancy\TenantContext;

/** Números da tela inicial do escritório (RF-009). */
final class DashboardService
{
    public const STALE_DAYS = 7;
    public const RECENT_DAYS = 7;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
    }

    /** @return array<string,mixed> */
    public function summary(TenantContext $ctx): array
    {
        $ctx->require('process.view');
        $now = $this->clock->now();
        $one = function (string $sql, array $params): mixed {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        };
        $t = $ctx->tenantId;
        $recent = (new MovementRepository($this->pdo, $ctx))->recent($now->modify('-' . self::RECENT_DAYS . ' days'), 10);
        return [
            'processes_total' => (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status <> 'archived'", [$t]),
            'processes_active' => (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active'", [$t]),
            'with_error' => (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active' AND last_error IS NOT NULL", [$t]),
            'never_checked' => (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active' AND last_success_at IS NULL", [$t]),
            'stale' => (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active' AND last_success_at IS NOT NULL AND last_success_at < ?",
                [$t, Clock::sql($now->modify('-' . self::STALE_DAYS . ' days'))]),
            'last_success_at' => $one('SELECT MAX(last_success_at) FROM processes WHERE tenant_id = ?', [$t]),
            'recent_movements' => $recent,
            'unread_notifications' => (new NotificationRepository($this->pdo, $ctx))->unreadCount(),
        ];
    }
}
