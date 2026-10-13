<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Radar\Exception\NotFound;
use Radar\Support\Clock;

/** Plano e assinatura de um escritório. */
final class PlanService
{
    /** Assinaturas que permitem incluir e monitorar processos. */
    public const ACTIVE_STATUSES = ['trialing', 'active', 'past_due'];

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
    }

    /** @return array{plan_code:string,plan_name:string,max_processes:int,max_users:int,max_oabs:int,min_interval_minutes:int,status:string,current_period_end:?string} */
    public function forTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.code, p.name, p.max_processes, p.max_users, p.max_oabs, p.min_interval_minutes, p.billing_model, p.price_per_search_cents, p.price_cents, s.status, s.current_period_end, s.created_at AS sub_created, s.first_paid_at, t.search_time, t.search_mode
               FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN tenants t ON t.id = s.tenant_id WHERE s.tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFound('Este escritório não tem assinatura.');
        }
        $trial = $row['status'] === 'trialing' && $row['current_period_end'] !== null;
        $left = null;
        $day = null;
        if ($row['current_period_end'] !== null) {
            $end = new DateTimeImmutable((string) $row['current_period_end'], new DateTimeZone('UTC'));
            $left = max(0, (int) ceil(($end->getTimestamp() - $this->clock->now()->getTimestamp()) / 86400));
        }
        if ($trial) {
            $day = min(SubscriptionService::TRIAL_DAYS, max(1, SubscriptionService::TRIAL_DAYS - (int) $left + 1));
        }
        $price = (int) $row['price_cents'];
        return [
            'price_cents' => $price, 'first_cycle' => $row['first_paid_at'] === null && $row['billing_model'] === 'monthly',
            'first_charge_cents' => (int) round($price / 2), 'first_charge_days' => 15,
            'trial_days' => SubscriptionService::TRIAL_DAYS, 'trial_day' => $day, 'days_left' => $left,
            'plan_code' => (string) $row['code'], 'plan_name' => (string) $row['name'],
            'max_processes' => (int) $row['max_processes'], 'max_users' => (int) $row['max_users'], 'max_oabs' => (int) $row['max_oabs'],
            'min_interval_minutes' => (int) $row['min_interval_minutes'],
            'billing_model' => (string) $row['billing_model'], 'price_per_search_cents' => (int) $row['price_per_search_cents'],
            'search_mode' => (string) $row['search_mode'],
            'search_time' => $row['search_time'] !== null ? substr((string) $row['search_time'], 0, 8) : null,
            'status' => (string) $row['status'], 'current_period_end' => $row['current_period_end'],
        ];
    }

    public function allowsMonitoring(string $subscriptionStatus): bool
    {
        return in_array($subscriptionStatus, self::ACTIVE_STATUSES, true);
    }
}
