<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Support\Clock;
use Radar\Support\Tribunals;
use Radar\Tenancy\TenantContext;

/**
 * Painel "ações por UF" e alerta de possível inscrição suplementar.
 *
 * Regra (Estatuto da Advocacia, art. 10, §2º): o advogado deve ter inscrição suplementar na Seccional onde
 * passar a exercer habitualmente a profissão, considerando-se habitualidade a participação anual em MAIS DE
 * CINCO causas em cada Seccional.
 *
 * Como é calculado aqui — e por que é só um INDICATIVO: conta processos DIFERENTES com publicação em nome da OAB
 * no ano da publicação, por UF. A UF vem do tribunal, e só é conhecida quando o tribunal cobre um único estado.
 * Publicação em tribunal de várias UFs (TRF1, TRF4, TRT8…) entra em "UF indeterminada" e fica fora do alerta.
 * Quem decide se há habitualidade é o advogado, conferindo com a Seccional.
 */
final class OabStats
{
    /** "Mais de cinco": o alerta dispara com 6 ou mais processos distintos no ano. */
    public const THRESHOLD = 5;
    public const GROUPS = ['estadual', 'federal', 'trabalho', 'outros'];

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
    }

    /** @return array<string,mixed> */
    public function summary(TenantContext $ctx, int $year): array
    {
        $ctx->require('oab.view');
        $t = $ctx->tenantId;

        $oabs = $this->pdo->prepare("SELECT id, lawyer_name, oab_number, oab_uf, supplementary_ufs FROM oab_registrations
            WHERE tenant_id = ? AND status <> 'archived' ORDER BY lawyer_name, id");
        $oabs->execute([$t]);
        $oabList = $oabs->fetchAll();

        $years = $this->pdo->prepare('SELECT DISTINCT pub_year FROM oab_publications WHERE tenant_id = ? AND pub_year IS NOT NULL ORDER BY pub_year DESC');
        $years->execute([$t]);

        // processos DIFERENTES por advogado × UF × justiça
        $perOab = $this->pdo->prepare('SELECT oab_id, uf, segment, COUNT(DISTINCT cnj_digits) AS n FROM oab_publications
            WHERE tenant_id = ? AND pub_year = ? GROUP BY oab_id, uf, segment');
        $perOab->execute([$t, $year]);
        // total por UF sem contar duas vezes o mesmo processo de dois advogados do escritório
        $total = $this->pdo->prepare('SELECT uf, segment, COUNT(DISTINCT cnj_digits) AS n FROM oab_publications
            WHERE tenant_id = ? AND pub_year = ? GROUP BY uf, segment');
        $total->execute([$t, $year]);

        $rows = [];
        $undetermined = 0;
        foreach ($total->fetchAll() as $r) {
            $group = Tribunals::segmentGroup((int) $r['segment']);
            if ($r['uf'] === null) {
                $undetermined += (int) $r['n'];
                continue;
            }
            $rows[$r['uf']] ??= ['uf' => $r['uf'], 'total' => 0, 'groups' => array_fill_keys(self::GROUPS, 0), 'by_oab' => []];
            $rows[$r['uf']]['groups'][$group] += (int) $r['n'];
            $rows[$r['uf']]['total'] += (int) $r['n'];
        }
        foreach ($perOab->fetchAll() as $r) {
            if ($r['uf'] === null) {
                continue;
            }
            $group = Tribunals::segmentGroup((int) $r['segment']);
            $rows[$r['uf']]['by_oab'][(int) $r['oab_id']] ??= array_fill_keys(self::GROUPS, 0) + ['total' => 0];
            $rows[$r['uf']]['by_oab'][(int) $r['oab_id']][$group] += (int) $r['n'];
            $rows[$r['uf']]['by_oab'][(int) $r['oab_id']]['total'] += (int) $r['n'];
        }
        uasort($rows, static fn (array $a, array $b) => $b['total'] <=> $a['total'] ?: strcmp($a['uf'], $b['uf']));

        $groups = array_fill_keys(self::GROUPS, 0);
        $all = 0;
        foreach ($rows as $row) {
            $all += $row['total'];
            foreach ($row['groups'] as $g => $n) {
                $groups[$g] += $n;
            }
        }
        $alerts = $this->pdo->prepare('SELECT a.id, a.oab_id, a.uf, a.alert_year, a.process_count, a.seen_at, r.lawyer_name, r.oab_number, r.oab_uf
            FROM oab_alerts a JOIN oab_registrations r ON r.id = a.oab_id AND r.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND a.alert_year = ? ORDER BY a.process_count DESC, a.uf');
        $alerts->execute([$t, $year]);

        return [
            'year' => $year,
            'years' => array_map('intval', $years->fetchAll(PDO::FETCH_COLUMN)),
            'oabs' => array_map(static fn (array $o) => [
                'id' => (int) $o['id'], 'name' => $o['lawyer_name'], 'number' => $o['oab_number'], 'uf' => $o['oab_uf'],
                'supplementary_ufs' => $o['supplementary_ufs'] === null || $o['supplementary_ufs'] === '' ? [] : explode(',', (string) $o['supplementary_ufs']),
            ], $oabList),
            'rows' => array_values($rows),
            'totals' => ['total' => $all, 'states' => count($rows)] + $groups,
            'undetermined' => $undetermined,
            'alerts' => $alerts->fetchAll(),
            'threshold' => self::THRESHOLD,
            'notice' => '[CONFIRMAR] Indicativo. Conta processos diferentes com publicação no ano, por UF do tribunal. Confira a habitualidade com a Seccional.',
        ];
    }

    /**
     * Recalcula os alertas de uma OAB: cria/atualiza os que valem e apaga os que deixaram de valer
     * (por exemplo, depois de cadastrar a inscrição suplementar). Seguro de repetir.
     */
    public function refreshAlerts(int $tenantId, int $oabId): void
    {
        $reg = $this->pdo->prepare('SELECT oab_uf, supplementary_ufs FROM oab_registrations WHERE id = ? AND tenant_id = ?');
        $reg->execute([$oabId, $tenantId]);
        $row = $reg->fetch();
        if ($row === false) {
            return;
        }
        $covered = array_filter(array_merge([(string) $row['oab_uf']], $row['supplementary_ufs'] ? explode(',', (string) $row['supplementary_ufs']) : []));
        $counts = $this->pdo->prepare('SELECT pub_year, uf, COUNT(DISTINCT cnj_digits) AS n FROM oab_publications
            WHERE tenant_id = ? AND oab_id = ? AND uf IS NOT NULL AND pub_year IS NOT NULL GROUP BY pub_year, uf');
        $counts->execute([$tenantId, $oabId]);
        $now = Clock::sql($this->clock->now());

        $keep = [];
        $upsert = $this->pdo->prepare(
            'INSERT INTO oab_alerts (tenant_id, oab_id, uf, alert_year, process_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE process_count = VALUES(process_count), updated_at = VALUES(updated_at)'
        );
        foreach ($counts->fetchAll() as $c) {
            if ((int) $c['n'] > self::THRESHOLD && !in_array($c['uf'], $covered, true)) {
                $upsert->execute([$tenantId, $oabId, $c['uf'], (int) $c['pub_year'], (int) $c['n'], $now, $now]);
                $keep[] = $c['uf'] . ':' . $c['pub_year'];
            }
        }
        $existing = $this->pdo->prepare('SELECT id, uf, alert_year FROM oab_alerts WHERE tenant_id = ? AND oab_id = ?');
        $existing->execute([$tenantId, $oabId]);
        $delete = $this->pdo->prepare('DELETE FROM oab_alerts WHERE id = ? AND tenant_id = ?');
        foreach ($existing->fetchAll() as $a) {
            if (!in_array($a['uf'] . ':' . $a['alert_year'], $keep, true)) {
                $delete->execute([$a['id'], $tenantId]);
            }
        }
    }

    public function markSeen(TenantContext $ctx, int $alertId): bool
    {
        $ctx->require('oab.view');
        $stmt = $this->pdo->prepare('UPDATE oab_alerts SET seen_at = ? WHERE id = ? AND tenant_id = ? AND seen_at IS NULL');
        $stmt->execute([Clock::sql($this->clock->now()), $alertId, $ctx->tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function unseenCount(TenantContext $ctx): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM oab_alerts WHERE tenant_id = ? AND seen_at IS NULL');
        $stmt->execute([$ctx->tenantId]);
        return (int) $stmt->fetchColumn();
    }
}
