<?php
declare(strict_types=1);

namespace Radar\Tenancy;

use PDO;
use Radar\Exception\Forbidden;

/**
 * "Quem está agindo, em qual escritório, com qual papel".
 *
 * É o ÚNICO jeito de obter acesso aos dados de um escritório: todo repositório e todo serviço
 * recebe um TenantContext e coloca `tenant_id` em cada consulta. O contexto nunca é montado a
 * partir de dado enviado pelo navegador: `resolve()` confere no banco que o usuário está ativo,
 * que o escritório está ativo e que o vínculo entre os dois está ativo.
 */
final class TenantContext
{
    private function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly Role $role,
    ) {
    }

    public static function resolve(PDO $pdo, int $userId, int $tenantId): self
    {
        $stmt = $pdo->prepare(
            "SELECT m.role FROM tenant_members m
               JOIN users u   ON u.id = m.user_id
               JOIN tenants t ON t.id = m.tenant_id
              WHERE m.user_id = ? AND m.tenant_id = ?
                AND m.status = 'active' AND u.status = 'active' AND t.status = 'active'"
        );
        $stmt->execute([$userId, $tenantId]);
        $role = $stmt->fetchColumn();
        if ($role === false) {
            throw new Forbidden('Acesso negado a este escritório.');
        }
        return new self($tenantId, $userId, Role::from((string) $role));
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->role->permissions(), true);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new Forbidden();
        }
    }
}
