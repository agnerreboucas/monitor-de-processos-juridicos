<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Exception\NotFound;

/** Plano e assinatura de um escritório. */
final class PlanService
{
    /** Assinaturas que permitem incluir e monitorar processos. */
    public const ACTIVE_STATUSES = ['trialing', 'active', 'past_due'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{plan_code:string,plan_name:string,max_processes:int,max_users:int,max_oabs:int,min_interval_minutes:int,status:string,current_period_end:?string} */
    public function forTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.code, p.name, p.max_processes, p.max_users, p.max_oabs, p.min_interval_minutes, p.billing_model, p.price_per_search_cents, s.status, s.current_period_end, t.search_time
               FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN tenants t ON t.id = s.tenant_id WHERE s.tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFound('Este escritório não tem assinatura.');
        }
        return [
            'plan_code' => (string) $row['code'], 'plan_name' => (string) $row['name'],
            'max_processes' => (int) $row['max_processes'], 'max_users' => (int) $row['max_users'], 'max_oabs' => (int) $row['max_oabs'],
            'min_interval_minutes' => (int) $row['min_interval_minutes'],
            'billing_model' => (string) $row['billing_model'], 'price_per_search_cents' => (int) $row['price_per_search_cents'],
            'search_time' => $row['search_time'] !== null ? substr((string) $row['search_time'], 0, 8) : null,
            'status' => (string) $row['status'], 'current_period_end' => $row['current_period_end'],
        ];
    }

    public function allowsMonitoring(string $subscriptionStatus): bool
    {
        return in_array($subscriptionStatus, self::ACTIVE_STATUSES, true);
    }
}
