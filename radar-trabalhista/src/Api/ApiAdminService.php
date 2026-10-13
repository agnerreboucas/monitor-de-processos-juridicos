<?php
declare(strict_types=1);

namespace Radar\Api;

use PDO;
use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;

/**
 * Painel do dono do SaaS para a venda da API: clientes, planos, chaves e uso.
 * Cada método confere no banco se quem chama é administrador da plataforma. Só mexe em cadastro e números de uso.
 */
final class ApiAdminService
{
    private Audit $audit;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
    }

    private function assertAdmin(int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_platform_admin = 1 AND status = 'active'");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            throw new Forbidden();
        }
    }

    private function monthStart(?string $month = null): string
    {
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return $month . '-01';
        }
        return $this->clock->now()->format('Y-m-01');
    }

    // ── planos ──────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function plans(int $adminId): array
    {
        $this->assertAdmin($adminId);
        return $this->pdo->query(
            'SELECT p.*, (SELECT COUNT(*) FROM api_clients c WHERE c.plan_id = p.id AND c.status = "active") AS clients FROM api_plans p ORDER BY p.price_cents, p.id'
        )->fetchAll();
    }

    /** @param array<string,mixed> $in */
    public function savePlan(int $adminId, ?int $id, array $in, ?string $ip = null): array
    {
        $this->assertAdmin($adminId);
        $code = strtolower(trim((string) ($in['code'] ?? '')));
        $name = trim((string) ($in['name'] ?? ''));
        if ($id === null && preg_match('/^[a-z0-9_-]{2,40}$/', $code) !== 1) {
            throw new ValidationError('Código do plano: 2 a 40 letras minúsculas, números, _ ou -.');
        }
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ValidationError('Informe o nome do plano (até 80 caracteres).');
        }
        $price = (int) ($in['price_cents'] ?? -1);
        $quota = (int) ($in['monthly_quota'] ?? 0);
        $rate = (int) ($in['rate_per_minute'] ?? 0);
        if ($price < 0 || $price > 100000000 || $quota < 1 || $quota > 1000000000 || $rate < 1 || $rate > 100000) {
            throw new ValidationError('Confira preço (centavos), cota mensal (mínimo 1) e limite por minuto (mínimo 1).');
        }
        $active = array_key_exists('active', $in) ? (int) (bool) $in['active'] : 1;
        $model = ($in['billing_model'] ?? 'monthly') === 'per_search' ? 'per_search' : 'monthly';
        $perSearch = (int) ($in['price_per_search_cents'] ?? 0);
        if ($perSearch < 0 || $perSearch > 1000000 || ($model === 'per_search' && $perSearch < 1)) {
            throw new ValidationError('No plano por busca, informe o preço de cada busca (em centavos, mínimo 1).');
        }
        if ($id === null) {
            try {
                $this->pdo->prepare('INSERT INTO api_plans (code, name, price_cents, monthly_quota, rate_per_minute, active, created_at, billing_model, price_per_search_cents) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$code, $name, $price, $quota, $rate, $active, Clock::sql($this->clock->now()), $model, $perSearch]);
            } catch (\PDOException $e) {
                throw new ValidationError('Já existe um plano com esse código.');
            }
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $this->mustPlan($id);
            $this->pdo->prepare('UPDATE api_plans SET name = ?, price_cents = ?, monthly_quota = ?, rate_per_minute = ?, active = ?, billing_model = ?, price_per_search_cents = ? WHERE id = ?')
                ->execute([$name, $price, $quota, $rate, $active, $model, $perSearch, $id]);
        }
        $this->audit->log('api.plan.saved', null, $adminId, 'api_plan', $id, $ip, ['name' => $name]);
        return $this->mustPlan($id);
    }

    /** @return array<string,mixed> */
    private function mustPlan(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_plans WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new NotFound('Plano não encontrado.');
    }

    // ── clientes ────────────────────────────────────────────────────────────

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function clients(int $adminId, string $q = '', string $status = ''): array
    {
        $this->assertAdmin($adminId);
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ? OR c.document LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (in_array($status, ['active', 'suspended', 'cancelled'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        $month = $this->monthStart();
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.email, c.company, c.status, c.created_at, c.plan_id, p.name AS plan_name, p.price_cents, p.billing_model, p.price_per_search_cents, c.spend_cap_cents,
                    COALESCE(c.quota_override, p.monthly_quota) AS quota, p.rate_per_minute,
                    (SELECT COALESCE(SUM(u.billable), 0) FROM api_usage_daily u WHERE u.client_id = c.id AND u.day >= ?) AS used_month,
                    (SELECT COUNT(*) FROM api_keys k WHERE k.client_id = c.id AND k.revoked_at IS NULL) AS keys_active,
                    (SELECT MAX(k.last_used_at) FROM api_keys k WHERE k.client_id = c.id) AS last_used_at
               FROM api_clients c JOIN api_plans p ON p.id = c.plan_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.created_at DESC, c.id DESC LIMIT 500'
        );
        $stmt->execute([$month, ...$params]);
        $items = array_map(fn (array $r) => $r + ['amount_cents' => $this->amountDue($r, (int) $r['used_month'])], $stmt->fetchAll());
        return ['items' => $items, 'total' => count($items)];
    }

    /** @param array<string,mixed> $in */
    public function createClient(int $adminId, array $in, ?string $ip = null): array
    {
        $this->assertAdmin($adminId);
        [$name, $email, $document, $company, $notes] = $this->clientFields($in);
        $plan = $this->mustPlan((int) ($in['plan_id'] ?? 0));
        if (!(int) $plan['active']) {
            throw new ValidationError('Esse plano está desativado.');
        }
        $now = Clock::sql($this->clock->now());
        try {
            $this->pdo->prepare('INSERT INTO api_clients (name, email, document, company, plan_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "active", ?, ?, ?)')
                ->execute([$name, $email, $document, $company, $plan['id'], $notes, $now, $now]);
        } catch (\PDOException $e) {
            throw new ValidationError('Já existe um cliente da API com esse e-mail.');
        }
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log('api.client.created', null, $adminId, 'api_client', $id, $ip, ['name' => $name]);
        return $this->client($adminId, $id);
    }

    /** @param array<string,mixed> $in */
    public function updateClient(int $adminId, int $id, array $in, ?string $ip = null): array
    {
        $this->assertAdmin($adminId);
        $current = $this->mustClient($id);
        [$name, $email, $document, $company, $notes] = $this->clientFields($in + $current);
        $status = array_key_exists('status', $in) ? (string) $in['status'] : (string) $current['status'];
        if (!in_array($status, ['active', 'suspended', 'cancelled'], true)) {
            throw new ValidationError('Situação inválida.');
        }
        $planId = array_key_exists('plan_id', $in) ? (int) $in['plan_id'] : (int) $current['plan_id'];
        $this->mustPlan($planId);
        $cap = array_key_exists('spend_cap_cents', $in) ? ($in['spend_cap_cents'] === null || $in['spend_cap_cents'] === '' ? null : (int) $in['spend_cap_cents']) : $current['spend_cap_cents'];
        if ($cap !== null && ($cap < 1 || $cap > 100000000)) {
            throw new ValidationError('Teto de gasto inválido (em centavos, mínimo 1) ou deixe em branco para não ter teto.');
        }
        $override = array_key_exists('quota_override', $in) ? ($in['quota_override'] === null || $in['quota_override'] === '' ? null : (int) $in['quota_override']) : $current['quota_override'];
        if ($override !== null && ($override < 1 || $override > 1000000000)) {
            throw new ValidationError('Cota especial inválida (mínimo 1) ou deixe em branco para usar a do plano.');
        }
        try {
            $this->pdo->prepare('UPDATE api_clients SET name = ?, email = ?, document = ?, company = ?, notes = ?, status = ?, plan_id = ?, quota_override = ?, spend_cap_cents = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, $email, $document, $company, $notes, $status, $planId, $override, $cap, Clock::sql($this->clock->now()), $id]);
        } catch (\PDOException $e) {
            throw new ValidationError('Já existe um cliente da API com esse e-mail.');
        }
        $this->audit->log('api.client.updated', null, $adminId, 'api_client', $id, $ip, ['status' => $status, 'plan_id' => $planId]);
        return $this->client($adminId, $id);
    }

    /** @return array{0:string,1:string,2:?string,3:?string,4:?string} */
    private function clientFields(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new ValidationError('Informe o nome do cliente.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new ValidationError('E-mail inválido.');
        }
        $doc = preg_replace('/\D/', '', (string) ($in['document'] ?? '')) ?? '';
        if ($doc !== '' && !in_array(strlen($doc), [11, 14], true)) {
            throw new ValidationError('CPF (11 dígitos) ou CNPJ (14 dígitos) inválido.');
        }
        $company = trim((string) ($in['company'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? ''));
        return [$name, $email, $doc !== '' ? $doc : null, $company !== '' ? mb_substr($company, 0, 160) : null, $notes !== '' ? mb_substr($notes, 0, 5000) : null];
    }

    /** @return array<string,mixed> */
    private function mustClient(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_clients WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new NotFound('Cliente não encontrado.');
    }

    /** @return array<string,mixed> cliente com chaves e uso dos últimos 30 dias */
    public function client(int $adminId, int $id): array
    {
        $this->assertAdmin($adminId);
        $c = $this->mustClient($id);
        $plan = $this->mustPlan((int) $c['plan_id']);
        $keys = $this->pdo->prepare('SELECT id, label, prefix, last_used_at, last_used_ip, expires_at, revoked_at, created_at FROM api_keys WHERE client_id = ? ORDER BY id DESC');
        $keys->execute([$id]);
        $since = $this->clock->now()->modify('-29 days')->format('Y-m-d');
        $usage = $this->pdo->prepare('SELECT day, SUM(requests) requests, SUM(billable) billable, SUM(errors) errors, SUM(cache_hits) cache_hits FROM api_usage_daily WHERE client_id = ? AND day >= ? GROUP BY day ORDER BY day');
        $usage->execute([$id, $since]);
        $byDay = [];
        foreach ($usage->fetchAll() as $row) {
            $byDay[$row['day']] = $row;
        }
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $this->clock->now()->modify("-$i days")->format('Y-m-d');
            $days[] = ['day' => $d, 'requests' => (int) ($byDay[$d]['requests'] ?? 0), 'billable' => (int) ($byDay[$d]['billable'] ?? 0), 'errors' => (int) ($byDay[$d]['errors'] ?? 0)];
        }
        $byEndpoint = $this->endpointsOf($id);
        $used = (int) array_sum(array_map(fn ($r) => (int) $r['billable'], $byEndpoint));
        $quota = (int) ($c['quota_override'] ?? $plan['monthly_quota']);
        $perSearch = $plan['billing_model'] === 'per_search';
        if ($perSearch && $c['spend_cap_cents'] !== null && (int) $plan['price_per_search_cents'] > 0) {
            $quota = intdiv((int) $c['spend_cap_cents'], (int) $plan['price_per_search_cents']);
        }
        return ['client' => $c, 'plan' => $plan, 'keys' => $keys->fetchAll(), 'days' => $days, 'quota' => $quota, 'used_month' => $used, 'by_endpoint' => $byEndpoint,
            'amount_cents' => $this->amountDue(['billing_model' => $plan['billing_model'], 'price_cents' => $plan['price_cents'], 'price_per_search_cents' => $plan['price_per_search_cents']], $used)];
    }

    /** O que cobrar no mês: plano mensal = a mensalidade; plano por busca = buscas bem-sucedidas × preço (falhas não entram). */
    private function amountDue(array $row, int $billable): int
    {
        return ($row['billing_model'] ?? 'monthly') === 'per_search' ? $billable * (int) $row['price_per_search_cents'] : (int) $row['price_cents'];
    }

    /** @return list<array<string,mixed>> */
    private function endpointsOf(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT endpoint, SUM(billable) billable, SUM(errors) errors FROM api_usage_daily WHERE client_id = ? AND day >= ? GROUP BY endpoint ORDER BY billable DESC');
        $stmt->execute([$clientId, $this->monthStart()]);
        return $stmt->fetchAll();
    }

    // ── chaves ──────────────────────────────────────────────────────────────

    /** @return array{key:array<string,mixed>,token:string} o token só existe nesta resposta */
    public function createKey(int $adminId, int $clientId, string $label, ?int $expiresInDays, ?string $ip = null): array
    {
        $this->assertAdmin($adminId);
        $client = $this->mustClient($clientId);
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) {
            throw new ValidationError('Dê um nome à chave (ex.: Produção, Teste). Até 80 caracteres.');
        }
        if ($client['status'] !== 'active') {
            throw new ValidationError('Cliente suspenso ou cancelado: reative antes de gerar chaves.');
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE client_id = ? AND revoked_at IS NULL');
        $count->execute([$clientId]);
        if ((int) $count->fetchColumn() >= 10) {
            throw new ValidationError('Limite de 10 chaves ativas por cliente. Revogue alguma antes.');
        }
        if ($expiresInDays !== null && ($expiresInDays < 1 || $expiresInDays > 3650)) {
            throw new ValidationError('Validade inválida (1 a 3650 dias) ou deixe em branco para não expirar.');
        }
        $k = ApiKeys::generate();
        $now = $this->clock->now();
        $this->pdo->prepare('INSERT INTO api_keys (client_id, label, prefix, key_hash, expires_at, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$clientId, $label, $k['prefix'], $k['hash'], $expiresInDays !== null ? Clock::sql($now->modify("+$expiresInDays days")) : null, Clock::sql($now), $adminId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log('api.key.created', null, $adminId, 'api_key', $id, $ip, ['client_id' => $clientId, 'label' => $label, 'prefix' => $k['prefix']]);
        return ['key' => ['id' => $id, 'label' => $label, 'prefix' => $k['prefix']], 'token' => $k['token']];
    }

    public function revokeKey(int $adminId, int $keyId, ?string $ip = null): void
    {
        $this->assertAdmin($adminId);
        $stmt = $this->pdo->prepare('UPDATE api_keys SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?');
        $stmt->execute([Clock::sql($this->clock->now()), $keyId]);
        $exists = $this->pdo->prepare('SELECT client_id FROM api_keys WHERE id = ?');
        $exists->execute([$keyId]);
        $clientId = $exists->fetchColumn();
        if ($clientId === false) {
            throw new NotFound('Chave não encontrada.');
        }
        $this->audit->log('api.key.revoked', null, $adminId, 'api_key', $keyId, $ip, ['client_id' => (int) $clientId]);
    }

    // ── visão geral e cobrança ──────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function overview(int $adminId): array
    {
        $this->assertAdmin($adminId);
        $month = $this->monthStart();
        $one = function (string $sql, array $p = []): int {
            $s = $this->pdo->prepare($sql);
            $s->execute($p);
            return (int) $s->fetchColumn();
        };
        $byStatus = $this->pdo->query('SELECT status, COUNT(*) n FROM api_clients GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $top = $this->pdo->prepare('SELECT c.id, c.name, SUM(u.billable) billable FROM api_usage_daily u JOIN api_clients c ON c.id = u.client_id WHERE u.day >= ? GROUP BY c.id, c.name ORDER BY billable DESC LIMIT 5');
        $top->execute([$month]);
        return [
            'clients' => ['active' => (int) ($byStatus['active'] ?? 0), 'suspended' => (int) ($byStatus['suspended'] ?? 0), 'cancelled' => (int) ($byStatus['cancelled'] ?? 0)],
            'keys_active' => $one('SELECT COUNT(*) FROM api_keys k JOIN api_clients c ON c.id = k.client_id WHERE k.revoked_at IS NULL AND c.status = "active"'),
            'requests_month' => $one('SELECT COALESCE(SUM(requests), 0) FROM api_usage_daily WHERE day >= ?', [$month]),
            'billable_month' => $one('SELECT COALESCE(SUM(billable), 0) FROM api_usage_daily WHERE day >= ?', [$month]),
            'errors_month' => $one('SELECT COALESCE(SUM(errors), 0) FROM api_usage_daily WHERE day >= ?', [$month]),
            'mrr_cents' => $one('SELECT COALESCE(SUM(p.price_cents), 0) FROM api_clients c JOIN api_plans p ON p.id = c.plan_id WHERE c.status = "active" AND p.billing_model = "monthly"'),
            'usage_revenue_cents' => $one('SELECT COALESCE(SUM(u.billable * p.price_per_search_cents), 0) FROM api_usage_daily u JOIN api_clients c ON c.id = u.client_id JOIN api_plans p ON p.id = c.plan_id WHERE p.billing_model = "per_search" AND u.day >= ?', [$month]),
            'top_clients' => $top->fetchAll(),
        ];
    }

    /** CSV de fechamento do mês: uma linha por cliente, com o que passou da cota. */
    public function usageCsv(int $adminId, ?string $month): string
    {
        $this->assertAdmin($adminId);
        $start = $this->monthStart($month);
        $end = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.email, c.document, p.name AS plan, p.price_cents, p.billing_model, p.price_per_search_cents, COALESCE(c.quota_override, p.monthly_quota) AS quota,
                    COALESCE(SUM(u.requests), 0) AS requests, COALESCE(SUM(u.billable), 0) AS billable, COALESCE(SUM(u.errors), 0) AS errors
               FROM api_clients c JOIN api_plans p ON p.id = c.plan_id
          LEFT JOIN api_usage_daily u ON u.client_id = c.id AND u.day >= ? AND u.day < ?
              GROUP BY c.id, c.name, c.email, c.document, p.name, p.price_cents, p.billing_model, p.price_per_search_cents, c.quota_override, p.monthly_quota ORDER BY c.name'
        );
        $stmt->execute([$start, $end]);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['mes', 'cliente', 'email', 'documento', 'plano', 'modelo', 'preco_mensal_reais', 'preco_por_busca_reais', 'buscas_cobradas', 'chamadas_totais', 'erros_nao_cobrados', 'valor_a_cobrar_reais'], ';');
        foreach ($stmt->fetchAll() as $r) {
            $perSearch = $r['billing_model'] === 'per_search';
            fputcsv($out, [substr($start, 0, 7), $this->csvSafe((string) $r['name']), $r['email'], $r['document'], $r['plan'], $perSearch ? 'por busca' : 'mensal',
                $perSearch ? '' : number_format($r['price_cents'] / 100, 2, ',', ''), $perSearch ? number_format($r['price_per_search_cents'] / 100, 2, ',', '') : '',
                $r['billable'], $r['requests'], $r['errors'], number_format($this->amountDue($r, (int) $r['billable']) / 100, 2, ',', '')], ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Evita que uma célula comece com = + - @ e vire fórmula na planilha. */
    private function csvSafe(string $v): string
    {
        return $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v;
    }
}
