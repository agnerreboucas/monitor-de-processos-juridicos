<?php
declare(strict_types=1);

namespace Radar\Repository;

use PDO;
use Radar\Tenancy\TenantContext;

final class MovementRepository
{
    public function __construct(private PDO $pdo, private TenantContext $ctx)
    {
    }

    /**
     * Histórico de um processo, do mais recente para o mais antigo (data da movimentação,
     * depois data de captura). Distingue as duas datas: `occurred_at` x `captured_at`.
     *
     * @return list<array<string,mixed>>
     */
    public function forProcess(int $processId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.occurred_at, m.captured_at, m.kind, m.description, m.source_url, m.is_baseline, m.metadata, s.code AS source, COALESCE(a.status, "open") AS action_status
               FROM process_movements m JOIN integration_sources s ON s.id = m.source_id
          LEFT JOIN movement_actions a ON a.movement_id = m.id AND a.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant AND m.process_id = :process
              ORDER BY m.occurred_at DESC, m.captured_at DESC, m.id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant', $this->ctx->tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':process', $processId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {         // metadados (órgão, partes, advogados…) já como lista, não como texto JSON
            $decoded = is_string($row['metadata'] ?? null) ? json_decode($row['metadata'], true) : null;
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> movimentações novas (fora do baseline) capturadas desde `$since` */
    public function recent(\DateTimeImmutable $since, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.process_id, p.cnj, p.tribunal, m.occurred_at, m.captured_at, m.kind, m.description
               FROM process_movements m JOIN processes p ON p.id = m.process_id AND p.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant AND m.is_baseline = 0 AND m.captured_at >= :since
              ORDER BY m.captured_at DESC, m.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':tenant', $this->ctx->tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Publicações do Diário dos processos ativos desde `$since`, com o que a tela "Ações e prazos" precisa:
     * dados do processo, metadados (partes, advogados, órgão) e se a ação já foi tratada.
     *
     * @return list<array<string,mixed>>
     */
    public function feed(\DateTimeImmutable $since, int $limit = 400): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.process_id, p.cnj, p.tribunal, p.client_label, p.client_side, u.name AS responsible_name,
                    m.occurred_at, m.captured_at, m.kind, m.description, m.source_url, m.metadata, m.is_baseline,
                    COALESCE(a.status, 'open') AS action_status, a.note AS action_note
               FROM process_movements m
               JOIN integration_sources s ON s.id = m.source_id AND s.code = 'djen'
               JOIN processes p ON p.id = m.process_id AND p.tenant_id = m.tenant_id
          LEFT JOIN users u ON u.id = p.responsible_user_id
          LEFT JOIN movement_actions a ON a.movement_id = m.id AND a.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tenant AND p.status <> 'archived' AND COALESCE(m.occurred_at, DATE(m.captured_at)) >= :since
              ORDER BY m.occurred_at DESC, m.id DESC LIMIT :limit"
        );
        $stmt->bindValue(':tenant', $this->ctx->tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $since->format('Y-m-d'));
        $stmt->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $decoded = is_string($row['metadata'] ?? null) ? json_decode($row['metadata'], true) : null;
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    /** Marca a publicação como tratada/ignorada/aberta. O vínculo com o escritório é conferido na própria consulta. */
    public function setAction(int $movementId, string $status, ?string $note, \DateTimeImmutable $now): void
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM process_movements WHERE id = ? AND tenant_id = ?');
        $exists->execute([$movementId, $this->ctx->tenantId]);
        if ($exists->fetchColumn() === false) {
            throw new \Radar\Exception\NotFound('Publicação não encontrada.');
        }
        $this->pdo->prepare(
            'INSERT INTO movement_actions (tenant_id, movement_id, status, user_id, note, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), user_id = VALUES(user_id), note = VALUES(note), updated_at = VALUES(updated_at)'
        )->execute([$this->ctx->tenantId, $movementId, $status, $this->ctx->userId, $note !== null ? mb_substr(trim($note), 0, 255) : null, $now->format('Y-m-d H:i:s')]);
    }
}
