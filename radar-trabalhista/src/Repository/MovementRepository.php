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
            'SELECT m.id, m.occurred_at, m.captured_at, m.kind, m.description, m.source_url, m.is_baseline, m.metadata, s.code AS source
               FROM process_movements m JOIN integration_sources s ON s.id = m.source_id
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
}
