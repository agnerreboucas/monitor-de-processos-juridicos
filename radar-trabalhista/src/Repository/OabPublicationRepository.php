<?php
declare(strict_types=1);

namespace Radar\Repository;

use PDO;
use Radar\Support\Tribunals;
use Radar\Tenancy\TenantContext;

/** Publicações por OAB do escritório. Toda consulta carrega o `tenant_id` do contexto. */
final class OabPublicationRepository
{
    public function __construct(private PDO $pdo, private TenantContext $ctx)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, r.lawyer_name, r.oab_number, r.oab_uf FROM oab_publications p
               JOIN oab_registrations r ON r.id = p.oab_id AND r.tenant_id = p.tenant_id
              WHERE p.id = ? AND p.tenant_id = ?'
        );
        $stmt->execute([$id, $this->ctx->tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array{oab_id?:int,uf?:string,group?:string,q?:string,year?:int,new_only?:bool} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['p.tenant_id = :tenant'];
        $params = ['tenant' => $this->ctx->tenantId];
        if (!empty($filters['oab_id'])) {
            $where[] = 'p.oab_id = :oab';
            $params['oab'] = (int) $filters['oab_id'];
        }
        if (!empty($filters['uf'])) {
            $where[] = 'p.uf = :uf';
            $params['uf'] = strtoupper((string) $filters['uf']);
        }
        $group = (string) ($filters['group'] ?? '');
        if (in_array($group, ['estadual', 'federal', 'trabalho', 'outros'], true)) {
            $codes = match ($group) {
                'estadual' => [8], 'federal' => [4], 'trabalho' => [5], default => [1, 2, 3, 6, 7, 9],
            };
            $where[] = 'p.segment IN (' . implode(',', $codes) . ')';
        }
        if (!empty($filters['year'])) {
            $where[] = 'p.pub_year = :year';
            $params['year'] = (int) $filters['year'];
        }
        if (!empty($filters['new_only'])) {
            $where[] = 'p.is_baseline = 0';
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $where[] = '(p.description LIKE :like1 OR p.cnj LIKE :like2 OR p.orgao LIKE :like3' . ($digits !== '' ? ' OR p.cnj_digits LIKE :digits' : '') . ')';
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $params['like1'] = $params['like2'] = $params['like3'] = $like;
            if ($digits !== '') {
                $params['digits'] = '%' . $digits . '%';
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM oab_publications p WHERE $sqlWhere");
        $count->execute($params);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.oab_id, p.cnj, p.tribunal, p.segment, p.uf, p.published_at, p.kind, p.doc_type, p.orgao,
                    p.class_name, SUBSTRING(p.description, 1, 400) AS excerpt, p.source_url, p.captured_at, p.is_baseline,
                    r.lawyer_name, r.oab_number, r.oab_uf,
                    EXISTS (SELECT 1 FROM processes x WHERE x.tenant_id = p.tenant_id AND x.cnj_digits = p.cnj_digits) AS is_monitored
               FROM oab_publications p JOIN oab_registrations r ON r.id = p.oab_id AND r.tenant_id = p.tenant_id
              WHERE $sqlWhere ORDER BY p.published_at DESC, p.id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map(static function (array $r): array {
            $r['group'] = Tribunals::segmentGroup((int) $r['segment']);
            $r['is_monitored'] = (bool) $r['is_monitored'];
            return $r;
        }, $stmt->fetchAll());
        return ['items' => $items, 'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $perPage];
    }
}
