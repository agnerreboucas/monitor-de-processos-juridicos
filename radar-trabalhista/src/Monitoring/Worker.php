<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Radar\Service\PlanService;
use Radar\Support\Clock;
use Radar\Support\Cnj;
use Throwable;

/**
 * Executa as consultas da fila. Pensado para rodar no cron, em lotes pequenos.
 *
 * Garantias:
 * - dois workers ao mesmo tempo não pegam o mesmo trabalho (SELECT ... FOR UPDATE SKIP LOCKED);
 * - a deduplicação é do banco (UNIQUE em tenant + processo + fonte + id da origem);
 * - falha de consulta NUNCA vira "sem novidade" e NUNCA marca o processo como atualizado;
 * - a primeira consulta bem-sucedida importa o histórico em silêncio (sem alertar mil movimentações antigas);
 * - o mesmo alerta não é criado duas vezes para a mesma movimentação, pessoa e canal.
 */
final class Worker
{
    /** @var array<string,SourceAdapter> */
    private array $adapters = [];
    private PlanService $plans;

    /** @param list<SourceAdapter> $adapters */
    public function __construct(
        private PDO $pdo,
        array $adapters,
        private Clock $clock = new Clock(),
        private string $workerId = 'worker',
        private int $staleLockMinutes = 15,
        private int $pauseBetweenJobsMicros = 0,   // respeito ao serviço público: pausa entre consultas
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->code()] = $adapter;
        }
        $this->plans = new PlanService($pdo);
    }

    /**
     * Cria um trabalho para cada processo ativo que já está na hora de consultar.
     * Só um agendador roda por vez (trava nomeada) e nunca duplica trabalho pendente.
     */
    public function enqueueDue(int $limit = 500): int
    {
        $lock = $this->pdo->query("SELECT GET_LOCK('radar_scheduler', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            return 0;
        }
        try {
            $now = Clock::sql($this->clock->now());
            $stmt = $this->pdo->prepare(
                "INSERT INTO monitoring_jobs (tenant_id, process_id, status, run_after, created_at)
                 SELECT p.tenant_id, p.id, 'queued', :now1, :now2
                   FROM processes p
                   JOIN tenants t ON t.id = p.tenant_id AND t.status = 'active'
                   JOIN subscriptions s ON s.tenant_id = p.tenant_id AND s.status IN ('trialing','active','past_due')
                  WHERE p.status = 'active' AND p.next_check_at IS NOT NULL AND p.next_check_at <= :now3
                    AND NOT EXISTS (SELECT 1 FROM monitoring_jobs j WHERE j.process_id = p.id AND j.status IN ('queued','running'))
                  ORDER BY p.next_check_at ASC LIMIT :limit"
            );
            $stmt->bindValue(':now1', $now);
            $stmt->bindValue(':now2', $now);
            $stmt->bindValue(':now3', $now);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('radar_scheduler')");
        }
    }

    /** Devolve à fila (ou dá por falho) o trabalho preso por um worker que morreu no meio. */
    public function recoverStale(): int
    {
        $limit = Clock::sql($this->clock->now()->sub(new DateInterval('PT' . $this->staleLockMinutes . 'M')));
        $stmt = $this->pdo->prepare(
            "UPDATE monitoring_jobs
                SET status = IF(attempts >= max_attempts, 'failed', 'queued'), locked_at = NULL, locked_by = NULL,
                    finished_at = IF(attempts >= max_attempts, ?, NULL), last_error = 'Interrompido antes de terminar.'
              WHERE status = 'running' AND locked_at < ?"
        );
        $stmt->execute([Clock::sql($this->clock->now()), $limit]);
        return $stmt->rowCount();
    }

    /** @return array{claimed:int,succeeded:int,failed:int,cancelled:int,new_movements:int} */
    public function runBatch(int $limit = 10): array
    {
        $result = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0, 'new_movements' => 0];
        foreach ($this->claim($limit) as $i => $job) {
            if ($i > 0 && $this->pauseBetweenJobsMicros > 0) {
                usleep($this->pauseBetweenJobsMicros);
            }
            $result['claimed']++;
            $outcome = $this->process($job);
            $result[$outcome['status']]++;
            $result['new_movements'] += $outcome['new'];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> trabalhos reservados para este worker */
    private function claim(int $limit): array
    {
        $now = Clock::sql($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM monitoring_jobs WHERE status = 'queued' AND run_after <= :now
                  ORDER BY run_after ASC, id ASC LIMIT :limit FOR UPDATE SKIP LOCKED"
            );
            $stmt->bindValue(':now', $now);
            $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                $this->pdo->commit();
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare(
                "UPDATE monitoring_jobs SET status = 'running', attempts = attempts + 1, locked_at = ?, locked_by = ?, started_at = ?
                  WHERE id IN ($in)"
            )->execute([$now, $this->workerId, $now, ...$ids]);
            $rows = $this->pdo->prepare("SELECT * FROM monitoring_jobs WHERE id IN ($in) ORDER BY run_after, id");
            $rows->execute($ids);
            $jobs = $rows->fetchAll();
            $this->pdo->commit();
            return $jobs;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $job
     * @return array{status:string,new:int}
     */
    private function process(array $job): array
    {
        $jobId = (int) $job['id'];
        $tenantId = (int) $job['tenant_id'];
        $processId = (int) $job['process_id'];
        $started = $this->clock->now();

        $stmt = $this->pdo->prepare('SELECT * FROM processes WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$processId, $tenantId]);
        $process = $stmt->fetch();
        $plan = $process !== false ? $this->safePlan($tenantId) : null;
        if ($process === false || $process['status'] !== 'active' || $plan === null || !$this->plans->allowsMonitoring($plan['status'])) {
            $this->finishJob($jobId, 'cancelled', 'Processo pausado, arquivado ou assinatura inativa.');
            return ['status' => 'cancelled', 'new' => 0];
        }

        $cnj = Cnj::parse((string) $process['cnj_digits']);
        $adapter = $this->adapters['djen'] ?? null;
        if ($cnj === null || $adapter === null) {
            return $this->fail($job, $process, $plan, $started, 'invalid_response', null, 'Processo ou fonte de consulta inválidos.');
        }
        try {
            $sourceId = $this->sourceId($adapter->code());
        } catch (Throwable $e) {
            return $this->fail($job, $process, $plan, $started, 'source_error', null, 'Fonte de consulta desativada ou não cadastrada.');
        }

        try {
            $since = $process['baseline_at'] !== null && $process['last_success_at'] !== null
                ? (new DateTimeImmutable((string) $process['last_success_at'], new \DateTimeZone('UTC')))->sub(new DateInterval('P3D'))
                : null;                                  // recua 3 dias: o diário às vezes publica com atraso
            $movements = $adapter->fetch($cnj, $since);
        } catch (SourceUnavailable $e) {
            return $this->fail($job, $process, $plan, $started, 'source_error', $sourceId, $e->getMessage());
        } catch (InvalidResponse $e) {
            return $this->fail($job, $process, $plan, $started, 'invalid_response', $sourceId, $e->getMessage());
        } catch (Throwable $e) {
            error_log('[radar] erro inesperado na consulta: ' . $e::class);
            return $this->fail($job, $process, $plan, $started, 'source_error', $sourceId, 'Erro inesperado na consulta.');
        }

        try {
            return $this->persist($job, $process, $plan, $started, $sourceId, $movements);
        } catch (Throwable $e) {
            error_log('[radar] erro ao gravar resultado: ' . $e::class . ' ' . $e->getMessage());
            return $this->fail($job, $process, $plan, $started, 'invalid_response', $sourceId, 'Não foi possível gravar o resultado da consulta.');
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $process
     * @param array<string,mixed> $plan
     * @param list<Movement> $movements
     * @return array{status:string,new:int}
     */
    private function persist(array $job, array $process, array $plan, DateTimeImmutable $started, int $sourceId, array $movements): array
    {
        $tenantId = (int) $process['tenant_id'];
        $processId = (int) $process['id'];
        $now = $this->clock->now();
        $nowSql = Clock::sql($now);
        $isBaseline = $process['baseline_at'] === null;

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO process_movements (tenant_id, process_id, source_id, external_id, occurred_at, captured_at, kind,
                                                description, description_raw, source_url, metadata, is_baseline)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id'
            );
            $newIds = [];
            $imported = 0;
            foreach ($movements as $m) {
                $insert->execute([$tenantId, $processId, $sourceId, $m->externalId, $m->occurredAt, $nowSql, $m->kind,
                    $m->description, $m->descriptionRaw, $m->sourceUrl,
                    $m->metadata === [] ? null : json_encode($m->metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $isBaseline ? 1 : 0]);
                if ($insert->rowCount() === 1) {          // 0 = já existia: não é novidade
                    $imported++;
                    if (!$isBaseline) {
                        $newIds[] = (int) $this->pdo->lastInsertId();
                    }
                }
            }
            foreach ($newIds as $movementId) {
                $this->notify($tenantId, $processId, $movementId, $process, $nowSql);
            }
            $this->pdo->prepare(
                'UPDATE processes SET last_attempt_at = ?, last_success_at = ?, last_error = NULL, next_check_at = ?,
                        baseline_at = COALESCE(baseline_at, ?) WHERE id = ? AND tenant_id = ?'
            )->execute([$nowSql, $nowSql, Clock::sql($now->add(new DateInterval('PT' . $plan['min_interval_minutes'] . 'M'))),
                $nowSql, $processId, $tenantId]);
            $message = $isBaseline
                ? "Primeira consulta: $imported movimentação(ões) importada(s) para o histórico, sem alerta."
                : ($newIds === [] ? 'Nenhuma movimentação nova.' : count($newIds) . ' movimentação(ões) nova(s).');
            $this->event($tenantId, $processId, (int) $job['id'], $sourceId,
                $newIds === [] ? 'no_news' : 'new_movements', count($newIds), $started, $message);
            $this->finishJob((int) $job['id'], 'succeeded', null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['status' => 'succeeded', 'new' => count($newIds)];
    }

    /**
     * Quem recebe o alerta: o responsável pelo processo (se ativo) ou, sem responsável,
     * os advogados e administradores do escritório. Um alerta de painel e um de e-mail por pessoa.
     *
     * @param array<string,mixed> $process
     */
    private function notify(int $tenantId, int $processId, int $movementId, array $process, string $nowSql): void
    {
        $recipients = $this->pdo->prepare(
            "SELECT user_id FROM tenant_members WHERE tenant_id = ? AND status = 'active'
                AND ((? IS NOT NULL AND user_id = ?) OR (? IS NULL AND role IN ('office_admin','lawyer')))"
        );
        $resp = $process['responsible_user_id'] !== null ? (int) $process['responsible_user_id'] : null;
        $recipients->execute([$tenantId, $resp, $resp, $resp]);
        $ids = $recipients->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === [] && $resp !== null) {          // o responsável saiu do escritório: avisa quem administra
            $fallback = $this->pdo->prepare("SELECT user_id FROM tenant_members WHERE tenant_id = ? AND status = 'active' AND role IN ('office_admin','lawyer')");
            $fallback->execute([$tenantId]);
            $ids = $fallback->fetchAll(PDO::FETCH_COLUMN);
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO notifications (tenant_id, user_id, process_id, movement_id, channel, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE id = id"
        );
        $wantsEmail = $ids === [] ? [] : $this->pdo->query('SELECT id FROM users WHERE email_notifications = 1 AND id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $userId) {
            foreach (['panel', 'email'] as $channel) {
                if ($channel === 'email' && !in_array((string) $userId, array_map('strval', $wantsEmail), true)) {
                    continue;                                      // a pessoa desligou o aviso por e-mail
                }
                $insert->execute([$tenantId, (int) $userId, $processId, $movementId, $channel, $nowSql]);
            }
        }
    }

    /**
     * Registra a falha, reprograma com espera progressiva e deixa claro no processo que NÃO foi atualizado.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $process
     * @param array<string,mixed> $plan
     * @return array{status:string,new:int}
     */
    private function fail(array $job, array $process, array $plan, DateTimeImmutable $started, string $outcome, ?int $sourceId, string $message): array
    {
        $now = $this->clock->now();
        $tenantId = (int) $process['tenant_id'];
        $processId = (int) $process['id'];
        $attempts = (int) $job['attempts'];
        $giveUp = $attempts >= (int) $job['max_attempts'];
        $message = mb_substr($message, 0, 500);

        $this->pdo->beginTransaction();
        try {
            // last_success_at NÃO muda: a tela continua mostrando a data da última consulta que deu certo
            $this->pdo->prepare('UPDATE processes SET last_attempt_at = ?, last_error = ?, next_check_at = ? WHERE id = ? AND tenant_id = ?')
                ->execute([Clock::sql($now), $message,
                    Clock::sql($giveUp ? $now->add(new DateInterval('PT' . $plan['min_interval_minutes'] . 'M')) : $now->add(self::backoff($attempts))),
                    $processId, $tenantId]);
            $this->event($tenantId, $processId, (int) $job['id'], $sourceId, $outcome, 0, $started, $message);
            if ($giveUp) {
                $this->finishJob((int) $job['id'], 'failed', $message);
            } else {
                $this->pdo->prepare("UPDATE monitoring_jobs SET status = 'queued', run_after = ?, locked_at = NULL, locked_by = NULL, last_error = ? WHERE id = ?")
                    ->execute([Clock::sql($now->add(self::backoff($attempts))), $message, $job['id']]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['status' => 'failed', 'new' => 0];     // reprogramada ou desistida: em ambos os casos esta tentativa falhou
    }

    /** 2, 4, 8, 16... minutos, no máximo 6 horas. */
    public static function backoff(int $attempts): DateInterval
    {
        return new DateInterval('PT' . min(360, 2 ** max(1, $attempts)) . 'M');
    }

    private function finishJob(int $jobId, string $status, ?string $error): void
    {
        $this->pdo->prepare('UPDATE monitoring_jobs SET status = ?, finished_at = ?, last_error = ?, locked_at = NULL, locked_by = NULL WHERE id = ?')
            ->execute([$status, Clock::sql($this->clock->now()), $error, $jobId]);
    }

    private function event(int $tenantId, int $processId, int $jobId, ?int $sourceId, string $outcome, int $newCount, DateTimeImmutable $started, string $message): void
    {
        $finished = $this->clock->now();
        $this->pdo->prepare(
            'INSERT INTO monitoring_events (tenant_id, process_id, job_id, source_id, outcome, new_count, started_at, finished_at, duration_ms, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $processId, $jobId, $sourceId, $outcome, $newCount, Clock::sql($started), Clock::sql($finished),
            max(0, (int) round(((float) $finished->format('U.u') - (float) $started->format('U.u')) * 1000)), $message]);
    }

    private function sourceId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM integration_sources WHERE code = ? AND enabled = 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Fonte $code não cadastrada ou desativada.");
        }
        return (int) $id;
    }

    /** @return array<string,mixed>|null */
    private function safePlan(int $tenantId): ?array
    {
        try {
            return $this->plans->forTenant($tenantId);
        } catch (\Radar\Exception\NotFound) {
            return null;
        }
    }
}
