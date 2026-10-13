<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Radar\Service\OabStats;
use Radar\Service\PlanService;
use Radar\Support\Clock;
use Radar\Service\BillingService;
use Radar\Support\Cnj;
use Radar\Support\Schedule;
use Radar\Support\Tribunals;
use Throwable;

/**
 * Consulta o diário por OAB. Mesmas garantias do monitor de processos:
 * reserva sem concorrência (SKIP LOCKED + lease), deduplicação no banco, primeira busca em silêncio,
 * falha com espera progressiva que NUNCA marca a OAB como atualizada.
 */
final class OabMonitor
{
    public function __construct(
        private PDO $pdo,
        private OabSource $source,
        private Clock $clock = new Clock(),
        private int $leaseMinutes = 15,
        private int $intervalHours = 6,
        private int $pauseBetweenMicros = 0,
    ) {
    }

    /** @return array{claimed:int,succeeded:int,failed:int,new_publications:int} */
    public function runDue(int $limit = 5): array
    {
        $result = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0, 'new_publications' => 0];
        foreach ($this->claim($limit) as $i => $reg) {
            if ($i > 0 && $this->pauseBetweenMicros > 0) {
                usleep($this->pauseBetweenMicros);
            }
            $result['claimed']++;
            if ((new BillingService($this->pdo, $this->clock))->capReached((int) $reg['tenant_id'])) {       // teto de gasto: tenta de novo amanhã
                $this->pdo->prepare('UPDATE oab_registrations SET next_check_at = ?, locked_until = NULL WHERE id = ? AND tenant_id = ?')
                    ->execute([Clock::sql($this->clock->now()->add(new DateInterval('PT12H'))), $reg['id'], $reg['tenant_id']]);
                $result['claimed']--;
                continue;
            }
            try {
                $result['new_publications'] += $this->process($reg);
                $result['succeeded']++;
            } catch (SourceUnavailable | InvalidResponse $e) {
                $this->fail($reg, $e->getMessage());
                $result['failed']++;
            } catch (Throwable $e) {
                error_log('[radar] erro na consulta por OAB: ' . $e::class . ' ' . $e->getMessage());
                $this->fail($reg, 'Erro inesperado na consulta.');
                $result['failed']++;
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function claim(int $limit): array
    {
        $now = $this->clock->now();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.id FROM oab_registrations r
                  WHERE r.status = 'active' AND r.next_check_at <= :now AND (r.locked_until IS NULL OR r.locked_until < :now2)
                    AND EXISTS (SELECT 1 FROM tenants t WHERE t.id = r.tenant_id AND t.status = 'active')
                    AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = r.tenant_id AND s.status IN ('trialing','active','past_due'))
                  ORDER BY r.next_check_at ASC, r.id ASC LIMIT :limit FOR UPDATE SKIP LOCKED"
            );
            $stmt->bindValue(':now', Clock::sql($now));
            $stmt->bindValue(':now2', Clock::sql($now));
            $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                $this->pdo->commit();
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("UPDATE oab_registrations SET locked_until = ? WHERE id IN ($in)")
                ->execute([Clock::sql($now->add(new DateInterval('PT' . $this->leaseMinutes . 'M'))), ...$ids]);
            $rows = $this->pdo->prepare("SELECT * FROM oab_registrations WHERE id IN ($in) ORDER BY next_check_at, id");
            $rows->execute($ids);
            $regs = $rows->fetchAll();
            $this->pdo->commit();
            return $regs;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $reg @return int publicações novas (fora da linha de base) */
    private function process(array $reg): int
    {
        $tenantId = (int) $reg['tenant_id'];
        $oabId = (int) $reg['id'];
        $now = $this->clock->now();
        $nowSql = Clock::sql($now);
        $today = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0, 0);
        $isBaseline = $reg['baseline_at'] === null;
        // primeira vez: desde 1º de janeiro (o alerta de inscrição suplementar conta o ano inteiro);
        // depois: recua 3 dias a partir da última consulta que deu certo (o diário publica com atraso)
        $since = $isBaseline || $reg['last_success_at'] === null
            ? $today->setDate((int) $today->format('Y'), 1, 1)
            : (new DateTimeImmutable((string) $reg['last_success_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0, 0)->sub(new DateInterval('P3D'));

        $publications = $this->source->fetchByOab((string) $reg['oab_number'], (string) $reg['oab_uf'], $since, $today);

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO oab_publications (tenant_id, oab_id, external_id, cnj, cnj_digits, tribunal, segment, uf, published_at, pub_year,
                        kind, doc_type, orgao, class_name, description, description_raw, source_url, captured_at, is_baseline)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id'
            );
            $new = 0;
            foreach ($publications as $p) {
                $cnj = Cnj::parse($p->cnjDigits);
                if ($cnj === null) {
                    continue;
                }
                $tribunal = $cnj->tribunal();   // o número do processo é a fonte mais confiável
                $year = $p->publishedAt !== null ? (int) substr($p->publishedAt, 0, 4) : null;
                $insert->execute([$tenantId, $oabId, $p->externalId, $cnj->formatted(), $cnj->digits, $tribunal, $cnj->segment,
                    Tribunals::uf($tribunal), $p->publishedAt, $year, $p->kind, $p->docType, $p->orgao, $p->className,
                    $p->description, $p->descriptionRaw, $p->sourceUrl, $nowSql, $isBaseline ? 1 : 0]);
                if ($insert->rowCount() === 1 && !$isBaseline) {
                    $new++;
                }
            }
            (new OabStats($this->pdo, $this->clock))->refreshAlerts($tenantId, $oabId);
            $this->pdo->prepare('UPDATE oab_registrations SET last_attempt_at = ?, last_success_at = ?, last_error = NULL, fail_count = 0,
                    baseline_at = COALESCE(baseline_at, ?), next_check_at = ?, locked_until = NULL WHERE id = ? AND tenant_id = ?')
                ->execute([$nowSql, $nowSql, $nowSql, Clock::sql($this->nextCheck($tenantId, $now)), $oabId, $tenantId]);
            (new BillingService($this->pdo, $this->clock))->record($tenantId, 'oab', true);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $new;
    }

    /** @param array<string,mixed> $reg */
    private function fail(array $reg, string $message): void
    {
        $now = $this->clock->now();
        $fails = (int) $reg['fail_count'] + 1;
        // last_success_at NÃO muda: a tela continua mostrando a última consulta que deu certo
        $this->pdo->prepare('UPDATE oab_registrations SET last_attempt_at = ?, last_error = ?, fail_count = ?, next_check_at = ?, locked_until = NULL
                              WHERE id = ? AND tenant_id = ?')
            ->execute([Clock::sql($now), mb_substr($message, 0, 500), $fails, Clock::sql($now->add(Worker::backoff($fails))), $reg['id'], $reg['tenant_id']]);
        (new BillingService($this->pdo, $this->clock))->record((int) $reg['tenant_id'], 'oab', false);      // falha não é cobrada
    }

    /** Horário fixo do escritório, se escolheu um; senão o intervalo padrão. */
    private function nextCheck(int $tenantId, DateTimeImmutable $now): DateTimeImmutable
    {
        $time = $this->pdo->prepare('SELECT search_time, search_mode FROM tenants WHERE id = ?');
        $time->execute([$tenantId]);
        $row = $time->fetch();
        if (($row['search_mode'] ?? 'daily') === 'six_hours') {
            return $now->add(new DateInterval('PT6H'));
        }
        $t = $row['search_time'] ?? null;
        return $t !== false && $t !== null ? Schedule::nextRun($now, substr((string) $t, 0, 8)) : $now->add(new DateInterval('PT' . $this->intervalHours . 'H'));
    }
}
