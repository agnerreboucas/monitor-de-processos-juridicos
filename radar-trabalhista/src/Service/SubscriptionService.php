<?php
declare(strict_types=1);

namespace Radar\Service;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;

/**
 * Ciclo da assinatura SEM provedor de pagamento (a cobrança automática entra depois e só chama renew()).
 *
 *   trialing/active + período vencido  → past_due   (continua monitorando, tela avisa)
 *   past_due + carência vencida        → suspended  (consultas pausam; os dados ficam guardados)
 *   renew() pelo dono (ou pelo provedor) → active com novo fim de período
 */
final class SubscriptionService
{
    public const TRIAL_DAYS = 14;
    public const GRACE_DAYS = 3;

    private Audit $audit;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
    }

    /** Fecha o que venceu. Seguro de repetir. @return array{past_due:int,suspended:int} */
    public function tick(): array
    {
        $now = Clock::sql($this->clock->now());
        $grace = Clock::sql($this->clock->now()->sub(new DateInterval('P' . self::GRACE_DAYS . 'D')));
        $pastDue = $this->pdo->prepare("UPDATE subscriptions SET status = 'past_due', updated_at = ?
            WHERE status IN ('trialing','active') AND current_period_end IS NOT NULL AND current_period_end < ?");
        $pastDue->execute([$now, $now]);
        $suspended = $this->pdo->prepare("UPDATE subscriptions SET status = 'suspended', suspended_reason = 'Período vencido sem renovação', updated_at = ?
            WHERE status = 'past_due' AND current_period_end IS NOT NULL AND current_period_end < ?");
        $suspended->execute([$now, $grace]);
        return ['past_due' => $pastDue->rowCount(), 'suspended' => $suspended->rowCount()];
    }

    /** O que a tela do escritório mostra no aviso do topo. @return array{level:string,message:string,days_left:?int} */
    public function banner(string $status, ?string $periodEnd): array
    {
        $daysLeft = null;
        if ($periodEnd !== null) {
            $end = new DateTimeImmutable($periodEnd, new \DateTimeZone('UTC'));
            $daysLeft = (int) ceil(($end->getTimestamp() - $this->clock->now()->getTimestamp()) / 86400);   // dias inteiros que ainda faltam
        }
        return match (true) {
            $status === 'suspended' => ['level' => 'erro', 'days_left' => $daysLeft,
                'message' => 'Assinatura suspensa: as consultas estão pausadas. Seus dados continuam guardados. Fale com o suporte para reativar.'],
            $status === 'cancelled' => ['level' => 'erro', 'days_left' => $daysLeft, 'message' => 'Assinatura cancelada.'],
            $status === 'past_due' => ['level' => 'amarelo', 'days_left' => $daysLeft,
                'message' => 'O período da assinatura venceu. As consultas continuam por mais ' . self::GRACE_DAYS . ' dias; depois pausam.'],
            $status === 'trialing' && $daysLeft !== null && $daysLeft <= 3 => ['level' => 'amarelo', 'days_left' => $daysLeft,
                'message' => $daysLeft <= 0 ? 'O teste grátis terminou.' : 'O teste grátis termina em ' . $daysLeft . ' dia(s).'],
            $status === 'trialing' => ['level' => 'info', 'days_left' => $daysLeft, 'message' => 'Teste grátis: ' . ($daysLeft ?? 0) . ' dia(s) restantes.'],
            default => ['level' => 'ok', 'days_left' => $daysLeft, 'message' => ''],
        };
    }

    /** Renova (pagamento confirmado, a mão ou pelo provedor): ativa e soma dias a partir do que for maior, hoje ou o fim atual. */
    public function renew(int $tenantId, int $days, ?int $byUserId = null, ?string $ip = null): void
    {
        if ($days < 1 || $days > 3660) {
            throw new ValidationError('Informe de 1 a 3660 dias.');
        }
        $sub = $this->row($tenantId);
        $base = $this->clock->now();
        if ($sub['current_period_end'] !== null) {
            $current = new DateTimeImmutable((string) $sub['current_period_end'], new \DateTimeZone('UTC'));
            if ($current > $base) {
                $base = $current;
            }
        }
        $this->pdo->prepare("UPDATE subscriptions SET status = 'active', current_period_end = ?, suspended_reason = NULL, first_paid_at = COALESCE(first_paid_at, ?), updated_at = ? WHERE tenant_id = ?")
            ->execute([Clock::sql($base->add(new DateInterval('P' . $days . 'D'))), Clock::sql($this->clock->now()), Clock::sql($this->clock->now()), $tenantId]);
        $this->audit->log('subscription.renewed', $tenantId, $byUserId, 'tenant', $tenantId, $ip, ['days' => $days]);
    }

    public function setStatus(int $tenantId, string $status, ?string $reason, ?int $byUserId = null, ?string $ip = null): void
    {
        if (!in_array($status, ['trialing', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
            throw new ValidationError('Situação inválida.');
        }
        $this->row($tenantId);
        $this->pdo->prepare('UPDATE subscriptions SET status = ?, suspended_reason = ?, updated_at = ? WHERE tenant_id = ?')
            ->execute([$status, in_array($status, ['suspended', 'cancelled'], true) ? ($reason !== null ? mb_substr($reason, 0, 160) : null) : null,
                Clock::sql($this->clock->now()), $tenantId]);
        $this->audit->log('subscription.status', $tenantId, $byUserId, 'tenant', $tenantId, $ip, ['status' => $status]);
    }

    public function setPlan(int $tenantId, string $planCode, ?int $byUserId = null, ?string $ip = null): void
    {
        $plan = $this->pdo->prepare('SELECT id, max_processes, max_users, max_oabs FROM plans WHERE code = ? AND active = 1');
        $plan->execute([$planCode]);
        $p = $plan->fetch();
        if ($p === false) {
            throw new ValidationError('Plano inexistente.');
        }
        $this->row($tenantId);
        // não deixa reduzir para um plano menor do que o escritório já usa: a pessoa precisa arquivar antes
        foreach ([['processes', "status <> 'archived'", 'max_processes', 'processos'], ['oab_registrations', "status <> 'archived'", 'max_oabs', 'OABs'],
                     ['tenant_members', "status <> 'disabled'", 'max_users', 'usuários']] as [$table, $where, $col, $label]) {
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE tenant_id = ? AND $where");
            $count->execute([$tenantId]);
            if ((int) $count->fetchColumn() > (int) $p[$col]) {
                throw new ValidationError("O escritório usa mais $label do que o plano permite. Peça para arquivar antes de reduzir o plano.");
            }
        }
        $this->pdo->prepare('UPDATE subscriptions SET plan_id = ?, updated_at = ? WHERE tenant_id = ?')
            ->execute([$p['id'], Clock::sql($this->clock->now()), $tenantId]);
        $this->audit->log('subscription.plan', $tenantId, $byUserId, 'tenant', $tenantId, $ip, ['plan' => $planCode]);
    }

    /** @return array<string,mixed> */
    private function row(int $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscriptions WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFound('Escritório sem assinatura.');
        }
        return $row;
    }
}
