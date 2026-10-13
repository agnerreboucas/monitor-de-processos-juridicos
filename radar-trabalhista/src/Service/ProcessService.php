<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use PDOException;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Repository\MovementRepository;
use Radar\Repository\ProcessRepository;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Support\Cnj;
use Radar\Tenancy\TenantContext;

/** Cadastro e gestão dos processos de um escritório. */
final class ProcessService
{
    private Audit $audit;
    private PlanService $plans;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
        $this->plans = new PlanService($pdo);
    }

    /** @return array<string,mixed> o processo criado */
    public function create(TenantContext $ctx, string $cnjInput, ?string $clientLabel = null,
                           ?int $responsibleUserId = null, ?string $notes = null, ?string $ip = null): array
    {
        $ctx->require('process.create');
        $cnj = Cnj::parse($cnjInput);
        if ($cnj === null) {
            throw new ValidationError('Número do processo inválido. Confira os dígitos (padrão CNJ).');
        }
        $clientLabel = self::cleanText($clientLabel, 160);
        $notes = self::cleanText($notes, 5000);
        $now = $this->clock->now();
        $nowSql = Clock::sql($now);

        $this->pdo->beginTransaction();
        try {
            // trava a linha do escritório: duas inclusões ao mesmo tempo não furam o limite do plano
            $this->pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE')->execute([$ctx->tenantId]);
            $plan = $this->plans->forTenant($ctx->tenantId);
            if (!$this->plans->allowsMonitoring($plan['status'])) {
                throw new LimitExceeded('A assinatura do escritório não está ativa. Regularize para cadastrar processos.');
            }
            if ((new ProcessRepository($this->pdo, $ctx))->countNotArchived() >= $plan['max_processes']) {
                throw new LimitExceeded('Limite de ' . $plan['max_processes'] . ' processos do plano ' . $plan['plan_name']
                    . ' atingido. Arquive processos ou mude de plano.');
            }
            if ($responsibleUserId !== null) {
                $this->assertActiveMember($ctx->tenantId, $responsibleUserId);
            }
            try {
                $this->pdo->prepare(
                    'INSERT INTO processes (tenant_id, cnj, cnj_digits, tribunal, segment, client_label, responsible_user_id,
                                            notes, next_check_at, created_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$ctx->tenantId, $cnj->formatted(), $cnj->digits, $cnj->tribunal(), $cnj->segment,
                    $clientLabel, $responsibleUserId, $notes, $nowSql, $ctx->userId, $nowSql]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ValidationError('Este processo já está cadastrado no escritório.');
                }
                throw $e;
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->enqueue($ctx->tenantId, $id, $now);
            $this->audit->log('process.created', $ctx->tenantId, $ctx->userId, 'process', $id, $ip, ['cnj' => $cnj->formatted()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->get($ctx, $id)['process'];
    }

    /**
     * Detalhe de um processo do escritório, com histórico e as últimas tentativas de consulta.
     *
     * @return array{process:array<string,mixed>,movements:list<array<string,mixed>>,events:list<array<string,mixed>>}
     */
    public function get(TenantContext $ctx, int $id, int $movementLimit = 500): array
    {
        $ctx->require('process.view');
        $process = (new ProcessRepository($this->pdo, $ctx))->find($id);
        if ($process === null) {
            throw new NotFound('Processo não encontrado.');   // inclusive quando é de outro escritório
        }
        $events = $this->pdo->prepare(
            'SELECT outcome, new_count, started_at, finished_at, duration_ms, message FROM monitoring_events
              WHERE tenant_id = ? AND process_id = ? ORDER BY id DESC LIMIT 20'
        );
        $events->execute([$ctx->tenantId, $id]);
        return [
            'process' => $process,
            'movements' => (new MovementRepository($this->pdo, $ctx))->forProcess($id, $movementLimit),
            'events' => $events->fetchAll(),
        ];
    }

    /** @param array{client_label?:?string,responsible_user_id?:?int,notes?:?string} $changes */
    public function update(TenantContext $ctx, int $id, array $changes, ?string $ip = null): void
    {
        $ctx->require('process.update');
        $this->mustFind($ctx, $id);
        $sets = [];
        $params = [];
        if (array_key_exists('client_label', $changes)) {
            $sets[] = 'client_label = ?';
            $params[] = self::cleanText($changes['client_label'], 160);
        }
        if (array_key_exists('notes', $changes)) {
            $sets[] = 'notes = ?';
            $params[] = self::cleanText($changes['notes'], 5000);
        }
        if (array_key_exists('client_side', $changes)) {
            $side = $changes['client_side'];
            if ($side !== null && $side !== '' && !in_array($side, ['A', 'P'], true)) {
                throw new ValidationError('Lado inválido: use A (autor/reclamante) ou P (réu/reclamado).');
            }
            $sets[] = 'client_side = ?';
            $params[] = $side === '' ? null : $side;
        }
        if (array_key_exists('responsible_user_id', $changes)) {
            if ($changes['responsible_user_id'] !== null) {
                $this->assertActiveMember($ctx->tenantId, (int) $changes['responsible_user_id']);
            }
            $sets[] = 'responsible_user_id = ?';
            $params[] = $changes['responsible_user_id'];
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE processes SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?')
            ->execute([...$params, $id, $ctx->tenantId]);
        $this->audit->log('process.updated', $ctx->tenantId, $ctx->userId, 'process', $id, $ip, ['fields' => array_keys($changes)]);
    }

    public function pause(TenantContext $ctx, int $id, ?string $ip = null): void
    {
        $ctx->require('process.update');
        $this->mustFind($ctx, $id);
        $this->pdo->prepare("UPDATE processes SET status = 'paused' WHERE id = ? AND tenant_id = ? AND status = 'active'")
            ->execute([$id, $ctx->tenantId]);
        $this->cancelPending($ctx->tenantId, $id);
        $this->audit->log('process.paused', $ctx->tenantId, $ctx->userId, 'process', $id, $ip);
    }

    public function resume(TenantContext $ctx, int $id, ?string $ip = null): void
    {
        $ctx->require('process.update');
        $this->mustFind($ctx, $id);
        $now = $this->clock->now();
        $this->pdo->prepare("UPDATE processes SET status = 'active', next_check_at = ? WHERE id = ? AND tenant_id = ? AND status = 'paused'")
            ->execute([Clock::sql($now), $id, $ctx->tenantId]);
        $this->audit->log('process.resumed', $ctx->tenantId, $ctx->userId, 'process', $id, $ip);
    }

    /** Arquivar libera uma vaga do plano e para as consultas. O histórico continua guardado. */
    public function archive(TenantContext $ctx, int $id, ?string $ip = null): void
    {
        $ctx->require('process.archive');
        $this->mustFind($ctx, $id);
        $this->pdo->prepare("UPDATE processes SET status = 'archived', next_check_at = NULL WHERE id = ? AND tenant_id = ?")
            ->execute([$id, $ctx->tenantId]);
        $this->cancelPending($ctx->tenantId, $id);
        $this->audit->log('process.archived', $ctx->tenantId, $ctx->userId, 'process', $id, $ip);
    }

    /** Pede uma consulta agora (ex.: botão "atualizar"), sem duplicar se já houver uma na fila. */
    public function requestCheck(TenantContext $ctx, int $id): bool
    {
        $ctx->require('process.update');
        $process = $this->mustFind($ctx, $id);
        if ($process['status'] !== 'active') {
            throw new ValidationError('Só processos ativos são consultados.');
        }
        return $this->enqueue($ctx->tenantId, $id, $this->clock->now());
    }

    private function enqueue(int $tenantId, int $processId, \DateTimeImmutable $runAfter): bool
    {
        $exists = $this->pdo->prepare("SELECT 1 FROM monitoring_jobs WHERE tenant_id = ? AND process_id = ? AND status IN ('queued','running') LIMIT 1");
        $exists->execute([$tenantId, $processId]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $this->pdo->prepare("INSERT INTO monitoring_jobs (tenant_id, process_id, status, run_after, created_at) VALUES (?, ?, 'queued', ?, ?)")
            ->execute([$tenantId, $processId, Clock::sql($runAfter), Clock::sql($this->clock->now())]);
        return true;
    }

    private function cancelPending(int $tenantId, int $processId): void
    {
        $this->pdo->prepare("UPDATE monitoring_jobs SET status = 'cancelled', finished_at = ? WHERE tenant_id = ? AND process_id = ? AND status = 'queued'")
            ->execute([Clock::sql($this->clock->now()), $tenantId, $processId]);
    }

    /** @return array<string,mixed> */
    private function mustFind(TenantContext $ctx, int $id): array
    {
        $process = (new ProcessRepository($this->pdo, $ctx))->find($id);
        if ($process === null) {
            throw new NotFound('Processo não encontrado.');
        }
        return $process;
    }

    private function assertActiveMember(int $tenantId, int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM tenant_members WHERE tenant_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$tenantId, $userId]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationError('O responsável precisa ser um integrante ativo do escritório.');
        }
    }

    private static function cleanText(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new ValidationError("Texto longo demais (máximo $max caracteres).");
        }
        return $text;
    }
}
