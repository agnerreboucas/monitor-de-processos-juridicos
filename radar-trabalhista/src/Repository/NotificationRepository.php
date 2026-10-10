<?php
declare(strict_types=1);

namespace Radar\Repository;

use PDO;
use Radar\Tenancy\TenantContext;

/** Notificações do painel: cada pessoa só enxerga e marca como lidas as suas. */
final class NotificationRepository
{
    public function __construct(private PDO $pdo, private TenantContext $ctx)
    {
    }

    /** @return list<array<string,mixed>> */
    public function mine(bool $onlyUnread = false, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.id, n.process_id, n.movement_id, n.status, n.created_at, n.read_at,
                    p.cnj, p.tribunal, m.occurred_at, m.captured_at, m.kind, m.description
               FROM notifications n
               JOIN processes p ON p.id = n.process_id AND p.tenant_id = n.tenant_id
               JOIN process_movements m ON m.id = n.movement_id AND m.tenant_id = n.tenant_id
              WHERE n.tenant_id = :tenant AND n.user_id = :user AND n.channel = 'panel'
                " . ($onlyUnread ? "AND n.status <> 'read'" : '') . '
              ORDER BY n.created_at DESC, n.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':tenant', $this->ctx->tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':user', $this->ctx->userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCount(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications
            WHERE tenant_id = ? AND user_id = ? AND channel = 'panel' AND status <> 'read'");
        $stmt->execute([$this->ctx->tenantId, $this->ctx->userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $id, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare("UPDATE notifications SET status = 'read', read_at = ?
            WHERE id = ? AND tenant_id = ? AND user_id = ? AND status <> 'read'");
        $stmt->execute([$now->format('Y-m-d H:i:s'), $id, $this->ctx->tenantId, $this->ctx->userId]);
        return $stmt->rowCount() > 0;
    }
}
