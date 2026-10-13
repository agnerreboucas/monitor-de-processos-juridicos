<?php
declare(strict_types=1);

namespace Radar\Service;

use DateTimeZone;
use PDO;
use Radar\Support\Clock;
use Radar\Support\Schedule;
use Radar\Tenancy\TenantContext;

/**
 * Panorama do dia de um escritório: o que foi atualizado, o que não deu para acessar (e por quê),
 * o que é novo e o que pede ação. Só números e motivos, sem texto de processo.
 */
final class DailySummary
{
    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
    }

    /** Traduz a mensagem técnica da falha para um motivo que o advogado entende. */
    public static function reason(?string $error): string
    {
        $e = mb_strtolower((string) $error);
        return match (true) {
            str_contains($e, 'sigil') => 'Processo em sigilo: o conteúdo não é público.',
            str_contains($e, '403') || str_contains($e, 'recusad') => 'A fonte recusou o acesso (pode ser bloqueio temporário).',
            str_contains($e, '429') || str_contains($e, 'limitou') => 'A fonte limitou as consultas; tentamos de novo mais tarde.',
            str_contains($e, 'indispon') || str_contains($e, 'fora do ar') || str_contains($e, 'sobrecarreg') || str_contains($e, 'sem resposta')
                || str_contains($e, 'rede') || str_contains($e, 'tempo esgotado') => 'O sistema do tribunal está fora do ar ou oscilando.',
            default => 'Não foi possível ler a resposta do tribunal; tentaremos de novo.',
        };
    }

    /** @return array<string,mixed> */
    public function forContext(TenantContext $ctx): array
    {
        $ctx->require('process.view');
        return $this->forTenant($ctx->tenantId);
    }

    /** @return array<string,mixed> */
    public function forTenant(int $tenantId): array
    {
        $now = $this->clock->now();
        $dayStart = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
        $one = function (string $sql, array $p): mixed {
            $s = $this->pdo->prepare($sql);
            $s->execute($p);
            return $s->fetchColumn();
        };
        $monitored = (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active'", [$tenantId]);
        $updated = (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active' AND last_success_at >= ?", [$tenantId, Clock::sql($dayStart)]);
        $waiting = (int) $one("SELECT COUNT(*) FROM processes WHERE tenant_id = ? AND status = 'active' AND last_success_at IS NULL AND last_error IS NULL", [$tenantId]);
        $failedRows = $this->pdo->prepare("SELECT tribunal, last_error FROM processes WHERE tenant_id = ? AND status = 'active' AND last_error IS NOT NULL");
        $failedRows->execute([$tenantId]);
        $groups = [];
        foreach ($failedRows->fetchAll() as $row) {
            $why = self::reason($row['last_error']);
            $groups[$why] ??= ['reason' => $why, 'count' => 0, 'tribunals' => []];
            $groups[$why]['count']++;
            if ($row['tribunal'] !== '') {
                $groups[$why]['tribunals'][$row['tribunal']] = true;
            }
        }
        $failed = array_values(array_map(static fn ($g) => ['reason' => $g['reason'], 'count' => $g['count'], 'tribunals' => array_keys($g['tribunals'])], $groups));
        usort($failed, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $sigilo = 0;
        foreach ($failed as $f) {
            if (str_starts_with($f['reason'], 'Processo em sigilo')) {
                $sigilo += $f['count'];
            }
        }
        $newMovements = (int) $one('SELECT COUNT(*) FROM process_movements WHERE tenant_id = ? AND is_baseline = 0 AND captured_at >= ?', [$tenantId, Clock::sql($now->modify('-24 hours'))]);
        $openActions = (int) $one("SELECT COUNT(*) FROM process_movements m LEFT JOIN movement_actions a ON a.movement_id = m.id AND a.tenant_id = m.tenant_id
                                    WHERE m.tenant_id = ? AND m.is_baseline = 0 AND COALESCE(a.status, 'open') = 'open' AND m.captured_at >= ?",
            [$tenantId, Clock::sql($now->modify('-30 days'))]);
        $time = $one('SELECT search_time FROM tenants WHERE id = ?', [$tenantId]);
        return [
            'date' => Schedule::localDay($now), 'monitored' => $monitored, 'updated_today' => $updated, 'waiting_first' => $waiting,
            'failed_total' => array_sum(array_column($failed, 'count')), 'failed' => $failed, 'sigilo' => $sigilo,
            'new_movements_24h' => $newMovements, 'open_actions' => $openActions,
            'last_success_at' => $one('SELECT MAX(last_success_at) FROM processes WHERE tenant_id = ?', [$tenantId]),
            'search_time' => $time !== false && $time !== null ? substr((string) $time, 0, 5) : null,
        ];
    }

    /** Texto do panorama (e-mail). @param array<string,mixed> $s */
    public static function text(array $s): string
    {
        $out = [];
        $out[] = "Processos monitorados: {$s['monitored']}";
        $out[] = "Atualizados hoje: {$s['updated_today']}";
        if ($s['waiting_first'] > 0) {
            $out[] = "Aguardando a primeira consulta: {$s['waiting_first']}";
        }
        $out[] = "Movimentações novas nas últimas 24 horas: {$s['new_movements_24h']}";
        if ($s['open_actions'] > 0) {
            $out[] = "Atos para você conferir (últimos 30 dias): {$s['open_actions']}";
        }
        if ($s['failed_total'] > 0) {
            $out[] = '';
            $out[] = "Não conseguimos acessar {$s['failed_total']} processo(s):";
            foreach ($s['failed'] as $f) {
                $out[] = "  - {$f['count']} processo(s)" . ($f['tribunals'] !== [] ? ' (' . implode(', ', $f['tribunals']) . ')' : '') . ': ' . $f['reason'];
            }
            $out[] = 'Isso não quer dizer "sem novidade". Tentamos de novo automaticamente.';
        }
        return implode("\n", $out);
    }
}
