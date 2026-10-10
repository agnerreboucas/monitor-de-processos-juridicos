<?php
declare(strict_types=1);

namespace Radar\Repository;

use PDO;
use Radar\Tenancy\TenantContext;

/** Acesso a processos. Toda consulta carrega `tenant_id = contexto`: não existe método sem ele. */
final class ProcessRepository
{
    public function __construct(private PDO $pdo, private TenantContext $ctx)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM processes WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $this->ctx->tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countNotArchived(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status <> 'archived'");
        $stmt->execute([$this->ctx->tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{q?:string,responsible_user_id?:int,status?:string,tribunal?:string,
     *              stale_days?:int,recent_news_days?:int,with_error?:bool} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 25, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $where = ['p.tenant_id = :tenant'];
        $params = ['tenant' => $this->ctx->tenantId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $like = '%' . addcslashes($q, '\\%_') . '%';
            // com prepares nativos cada nome de parâmetro só pode aparecer uma vez
            $where[] = '(p.client_label LIKE :like1 OR p.cnj LIKE :like2' . ($digits !== '' ? ' OR p.cnj_digits LIKE :digits' : '') . ')';
            $params['like1'] = $like;
            $params['like2'] = $like;
            if ($digits !== '') {
                $params['digits'] = '%' . $digits . '%';
            }
        }
        if (!empty($filters['responsible_user_id'])) {
            $where[] = 'p.responsible_user_id = :resp';
            $params['resp'] = (int) $filters['responsible_user_id'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'paused', 'archived'], true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        } else {
            $where[] = "p.status <> 'archived'";
        }
        if (!empty($filters['tribunal'])) {
            $where[] = 'p.tribunal = :tribunal';
            $params['tribunal'] = strtoupper((string) $filters['tribunal']);
        }
        if (!empty($filters['stale_days'])) {
            $where[] = '(p.last_success_at IS NULL OR p.last_success_at < :stale)';
            $params['stale'] = $now->modify('-' . (int) $filters['stale_days'] . ' days')->format('Y-m-d H:i:s');
        }
        if (!empty($filters['with_error'])) {
            $where[] = 'p.last_error IS NOT NULL';
        }
        if (!empty($filters['recent_news_days'])) {
            $where[] = 'EXISTS (SELECT 1 FROM process_movements m WHERE m.process_id = p.id AND m.tenant_id = p.tenant_id
                                 AND m.is_baseline = 0 AND m.captured_at >= :news)';
            $params['news'] = $now->modify('-' . (int) $filters['recent_news_days'] . ' days')->format('Y-m-d H:i:s');
        }

        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM processes p WHERE $sqlWhere");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $stmt = $this->pdo->prepare(
            "SELECT p.*, u.name AS responsible_name FROM processes p LEFT JOIN users u ON u.id = p.responsible_user_id
              WHERE $sqlWhere ORDER BY p.created_at DESC, p.id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
