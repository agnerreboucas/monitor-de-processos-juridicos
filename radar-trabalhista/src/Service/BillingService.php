<?php
declare(strict_types=1);

namespace Radar\Service;

use DateTimeImmutable;
use PDO;
use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Support\Schedule;
use Radar\Tenancy\TenantContext;

/**
 * Cobrança por busca. Só a busca que deu certo é cobrada; a que falha fica registrada como falha e custa zero.
 * Durante o período de teste nada é cobrado. Este serviço não cobra de ninguém: ele conta e mostra o extrato,
 * e o dono do SaaS cobra por fora (Pix, boleto…).
 */
final class BillingService
{
    private Audit $audit;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
    }

    public function record(int $tenantId, string $kind, bool $ok): void
    {
        $this->pdo->prepare(
            'INSERT INTO search_usage_daily (tenant_id, day, kind, ok, failed) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ok = ok + VALUES(ok), failed = failed + VALUES(failed)'
        )->execute([$tenantId, Schedule::localDay($this->clock->now()), $kind, $ok ? 1 : 0, $ok ? 0 : 1]);
    }

    private function monthStart(?string $month): string
    {
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return $month . '-01';
        }
        return substr(Schedule::localDay($this->clock->now()), 0, 8) . '01';
    }

    /** @return array<string,mixed> */
    public function statement(int $tenantId, ?string $month = null): array
    {
        $start = $this->monthStart($month);
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $plan = $this->pdo->prepare(
            'SELECT p.name, p.price_cents, p.billing_model, p.price_per_search_cents, s.status, t.spend_cap_cents, t.search_time
               FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id JOIN plans p ON p.id = s.plan_id WHERE t.id = ?'
        );
        $plan->execute([$tenantId]);
        $p = $plan->fetch() ?: ['name' => '', 'price_cents' => 0, 'billing_model' => 'monthly', 'price_per_search_cents' => 0, 'status' => 'trialing', 'spend_cap_cents' => null, 'search_time' => null];
        $rows = $this->pdo->prepare('SELECT kind, SUM(ok) ok, SUM(failed) failed FROM search_usage_daily WHERE tenant_id = ? AND day >= ? AND day < ? GROUP BY kind');
        $rows->execute([$tenantId, $start, $end]);
        $byKind = ['process' => ['ok' => 0, 'failed' => 0], 'oab' => ['ok' => 0, 'failed' => 0], 'lookup' => ['ok' => 0, 'failed' => 0]];
        foreach ($rows->fetchAll() as $r) {
            $byKind[$r['kind']] = ['ok' => (int) $r['ok'], 'failed' => (int) $r['failed']];
        }
        $ok = array_sum(array_column($byKind, 'ok'));
        $failed = array_sum(array_column($byKind, 'failed'));
        $perSearch = $p['billing_model'] === 'per_search';
        $trial = $p['status'] === 'trialing';
        $amount = $trial ? 0 : ($perSearch ? $ok * (int) $p['price_per_search_cents'] : (int) $p['price_cents']);
        return ['month' => substr($start, 0, 7), 'plan_name' => $p['name'], 'model' => $p['billing_model'], 'price_per_search_cents' => (int) $p['price_per_search_cents'],
            'ok' => $ok, 'failed' => $failed, 'by_kind' => $byKind, 'trial' => $trial, 'amount_cents' => $amount,
            'would_be_cents' => $perSearch ? $ok * (int) $p['price_per_search_cents'] : (int) $p['price_cents'],
            'spend_cap_cents' => $p['spend_cap_cents'] !== null ? (int) $p['spend_cap_cents'] : null, 'search_time' => $p['search_time'] !== null ? substr((string) $p['search_time'], 0, 5) : null,
            'note' => 'Só buscas bem-sucedidas são cobradas. Buscas que falham não custam nada.'];
    }

    /** Teto de gasto do mês atingido? (só vale para plano por busca, fora do teste) */
    public function capReached(int $tenantId): bool
    {
        $s = $this->statement($tenantId);
        return $s['model'] === 'per_search' && !$s['trial'] && $s['spend_cap_cents'] !== null && $s['amount_cents'] >= $s['spend_cap_cents'];
    }

    /** Horário das buscas automáticas e teto de gasto: só o administrador do escritório muda. */
    public function saveSettings(TenantContext $ctx, array $in, ?string $ip = null): array
    {
        $ctx->require('members.manage');
        $sets = [];
        $params = [];
        if (array_key_exists('search_time', $in)) {
            $time = Schedule::normalize(is_string($in['search_time']) ? $in['search_time'] : null);
            if ($time === false) {
                throw new ValidationError('Horário inválido. Use hora e minuto, por exemplo 05:30.');
            }
            $sets[] = 'search_time = ?';
            $params[] = $time;
            if ($time !== null) {      // as buscas já agendadas passam para o próximo horário escolhido
                $next = Clock::sql(Schedule::nextRun($this->clock->now(), $time));
                $now = Clock::sql($this->clock->now());
                foreach (['processes', 'oab_registrations'] as $table) {
                    $this->pdo->prepare("UPDATE $table SET next_check_at = ? WHERE tenant_id = ? AND status = 'active' AND next_check_at > ?")->execute([$next, $ctx->tenantId, $now]);
                }
            }
        }
        if (array_key_exists('spend_cap_cents', $in)) {
            $cap = $in['spend_cap_cents'] === null || $in['spend_cap_cents'] === '' ? null : (int) $in['spend_cap_cents'];
            if ($cap !== null && ($cap < 100 || $cap > 100000000)) {
                throw new ValidationError('Teto de gasto inválido: informe em reais, no mínimo R$ 1,00, ou deixe em branco.');
            }
            $sets[] = 'spend_cap_cents = ?';
            $params[] = $cap;
        }
        if ($sets !== []) {
            $this->pdo->prepare('UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute([...$params, $ctx->tenantId]);
            $this->audit->log('tenant.search_settings', $ctx->tenantId, $ctx->userId, 'tenant', $ctx->tenantId, $ip, array_keys($in));
        }
        return $this->statement($ctx->tenantId);
    }

    /** CSV de fechamento do mês para o dono do SaaS: uma linha por escritório. */
    public function csvForOwner(int $adminId, ?string $month): string
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_platform_admin = 1 AND status = 'active'");
        $stmt->execute([$adminId]);
        if ($stmt->fetchColumn() === false) {
            throw new Forbidden();
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['mes', 'escritorio', 'plano', 'modelo', 'em_teste', 'preco_por_busca_reais', 'buscas_cobradas', 'buscas_com_falha_nao_cobradas', 'valor_a_cobrar_reais', 'teto_de_gasto_reais'], ';');
        foreach ($this->pdo->query('SELECT id, name FROM tenants ORDER BY name')->fetchAll() as $t) {
            $s = $this->statement((int) $t['id'], $month);
            $name = (string) $t['name'];
            fputcsv($out, [$s['month'], $name !== '' && str_contains('=+-@', $name[0]) ? "'" . $name : $name, $s['plan_name'], $s['model'] === 'per_search' ? 'por busca' : 'mensal', $s['trial'] ? 'sim' : 'não',
                $s['model'] === 'per_search' ? number_format($s['price_per_search_cents'] / 100, 2, ',', '') : '', $s['ok'], $s['failed'], number_format($s['amount_cents'] / 100, 2, ',', ''),
                $s['spend_cap_cents'] !== null ? number_format($s['spend_cap_cents'] / 100, 2, ',', '') : ''], ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
