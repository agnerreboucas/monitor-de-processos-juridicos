<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use PDOException;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Repository\OabPublicationRepository;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Support\Tribunals;
use Radar\Tenancy\TenantContext;

/** OABs dos advogados do escritório (o que se acompanha no diário). */
final class OabService
{
    private Audit $audit;
    private PlanService $plans;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
        $this->plans = new PlanService($pdo);
    }

    /**
     * @param list<string> $supplementaryUfs inscrições suplementares que o advogado JÁ tem
     * @return array<string,mixed>
     */
    public function add(TenantContext $ctx, string $number, string $uf, string $lawyerName, array $supplementaryUfs = [], ?string $ip = null): array
    {
        $ctx->require('oab.manage');
        $digits = self::normalizeNumber($number);
        $uf = strtoupper(trim($uf));
        if (!in_array($uf, Tribunals::UFS, true)) {
            throw new ValidationError('UF da OAB inválida.');
        }
        $lawyerName = trim($lawyerName);
        if ($lawyerName === '' || mb_strlen($lawyerName) > 160) {
            throw new ValidationError('Informe o nome do advogado(a).');
        }
        $supplementary = self::normalizeUfs($supplementaryUfs, $uf);
        $now = Clock::sql($this->clock->now());

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE')->execute([$ctx->tenantId]);
            $plan = $this->plans->forTenant($ctx->tenantId);
            if (!$this->plans->allowsMonitoring($plan['status'])) {
                throw new LimitExceeded('A assinatura do escritório não está ativa.');
            }
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM oab_registrations WHERE tenant_id = ? AND status <> 'archived'");
            $count->execute([$ctx->tenantId]);
            if ((int) $count->fetchColumn() >= $plan['max_oabs']) {
                throw new LimitExceeded('Limite de ' . $plan['max_oabs'] . ' OABs do plano ' . $plan['plan_name'] . ' atingido.');
            }
            try {
                $this->pdo->prepare('INSERT INTO oab_registrations (tenant_id, oab_number, oab_uf, lawyer_name, supplementary_ufs, next_check_at, created_by, created_at)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$ctx->tenantId, $digits, $uf, $lawyerName, $supplementary === [] ? null : implode(',', $supplementary), $now, $ctx->userId, $now]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ValidationError('Esta OAB já está cadastrada no escritório.');
                }
                throw $e;
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log('oab.added', $ctx->tenantId, $ctx->userId, 'oab', $id, $ip, ['oab' => "$digits/$uf"]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->get($ctx, $id);
    }

    /** @return list<array<string,mixed>> */
    public function list(TenantContext $ctx): array
    {
        $ctx->require('oab.view');
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.oab_number, r.oab_uf, r.lawyer_name, r.supplementary_ufs, r.status, r.baseline_at, r.last_attempt_at,
                    r.last_success_at, r.last_error, r.fail_count,
                    (SELECT COUNT(*) FROM oab_publications p WHERE p.oab_id = r.id AND p.tenant_id = r.tenant_id) AS publications
               FROM oab_registrations r WHERE r.tenant_id = ? AND r.status <> 'archived' ORDER BY r.lawyer_name, r.id"
        );
        $stmt->execute([$ctx->tenantId]);
        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $ctx, int $id): array
    {
        $ctx->require('oab.view');
        $stmt = $this->pdo->prepare('SELECT * FROM oab_registrations WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $ctx->tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFound('OAB não encontrada.');
        }
        return self::present($row);
    }

    /** @param list<string>|null $supplementaryUfs */
    public function update(TenantContext $ctx, int $id, ?string $lawyerName, ?array $supplementaryUfs, ?string $ip = null): void
    {
        $ctx->require('oab.manage');
        $current = $this->get($ctx, $id);
        $sets = [];
        $params = [];
        if ($lawyerName !== null) {
            $lawyerName = trim($lawyerName);
            if ($lawyerName === '' || mb_strlen($lawyerName) > 160) {
                throw new ValidationError('Informe o nome do advogado(a).');
            }
            $sets[] = 'lawyer_name = ?';
            $params[] = $lawyerName;
        }
        if ($supplementaryUfs !== null) {
            $ufs = self::normalizeUfs($supplementaryUfs, (string) $current['oab_uf']);
            $sets[] = 'supplementary_ufs = ?';
            $params[] = $ufs === [] ? null : implode(',', $ufs);
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE oab_registrations SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?')
            ->execute([...$params, $id, $ctx->tenantId]);
        if ($supplementaryUfs !== null) {
            (new OabStats($this->pdo, $this->clock))->refreshAlerts($ctx->tenantId, $id);   // quem regularizou a inscrição sai do alerta
        }
        $this->audit->log('oab.updated', $ctx->tenantId, $ctx->userId, 'oab', $id, $ip);
    }

    public function setStatus(TenantContext $ctx, int $id, string $action, ?string $ip = null): void
    {
        $ctx->require('oab.manage');
        $this->get($ctx, $id);
        [$status, $next] = match ($action) {
            'pause' => ['paused', null],
            'resume' => ['active', Clock::sql($this->clock->now())],
            'archive' => ['archived', null],
            default => throw new ValidationError('Ação inválida.'),
        };
        $this->pdo->prepare('UPDATE oab_registrations SET status = ?, next_check_at = ?, locked_until = NULL WHERE id = ? AND tenant_id = ?')
            ->execute([$status, $next, $id, $ctx->tenantId]);
        $this->audit->log('oab.' . $action, $ctx->tenantId, $ctx->userId, 'oab', $id, $ip);
    }

    /** "Consultar agora": adianta a próxima consulta desta OAB. */
    public function requestCheck(TenantContext $ctx, int $id): void
    {
        $ctx->require('oab.manage');
        $oab = $this->get($ctx, $id);
        if ($oab['status'] !== 'active') {
            throw new ValidationError('Só OABs ativas são consultadas.');
        }
        $this->pdo->prepare('UPDATE oab_registrations SET next_check_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([Clock::sql($this->clock->now()), $id, $ctx->tenantId]);
    }

    /** Passa a monitorar, como processo, o processo de uma publicação encontrada pela OAB. */
    public function monitorPublication(TenantContext $ctx, int $publicationId, ?int $responsibleUserId = null, ?string $ip = null): array
    {
        $ctx->require('oab.view');
        $publication = (new OabPublicationRepository($this->pdo, $ctx))->find($publicationId);
        if ($publication === null) {
            throw new NotFound('Publicação não encontrada.');
        }
        return (new ProcessService($this->pdo, $this->clock))->create($ctx, (string) $publication['cnj'], null, $responsibleUserId, null, $ip);
    }

    public static function normalizeNumber(string $number): string
    {
        $clean = preg_replace('/[\s.\-\/]/', '', $number) ?? '';
        if (preg_match('/^(\d{1,7})[A-Za-z]?$/', $clean, $m) !== 1 || (int) $m[1] === 0) {
            throw new ValidationError('Número da OAB inválido. Use só os números (a letra final, como em 12345A, é ignorada).');
        }
        return ltrim($m[1], '0');
    }

    /**
     * @param list<mixed> $ufs
     * @return list<string>
     */
    public static function normalizeUfs(array $ufs, string $principal): array
    {
        $out = [];
        foreach ($ufs as $uf) {
            $uf = strtoupper(trim((string) $uf));
            if ($uf === '' || $uf === $principal) {
                continue;
            }
            if (!in_array($uf, Tribunals::UFS, true)) {
                throw new ValidationError("UF suplementar inválida: $uf.");
            }
            $out[$uf] = $uf;
        }
        sort($out);
        return array_values($out);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function present(array $row): array
    {
        $row['supplementary_ufs'] = ($row['supplementary_ufs'] ?? '') === '' || $row['supplementary_ufs'] === null
            ? [] : explode(',', (string) $row['supplementary_ufs']);
        return $row;
    }
}
