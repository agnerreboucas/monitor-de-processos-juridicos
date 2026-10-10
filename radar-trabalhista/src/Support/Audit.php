<?php
declare(strict_types=1);

namespace Radar\Support;

use PDO;

/** Registro de eventos críticos. Nunca grava senha, token ou segredo. */
final class Audit
{
    private const SECRET_KEY = '/pass|senha|token|secret|segredo|key|hash/i';

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
    }

    /** @param array<string,mixed> $meta */
    public function log(string $action, ?int $tenantId = null, ?int $userId = null, ?string $entityType = null,
                        ?int $entityId = null, ?string $ip = null, array $meta = []): void
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            $clean[$key] = preg_match(self::SECRET_KEY, (string) $key) === 1 ? '[omitido]' : $value;
        }
        $this->pdo->prepare(
            'INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, ip, meta, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenantId, $userId, $action, $entityType, $entityId, $ip !== null ? substr($ip, 0, 45) : null,
            $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            Clock::sql($this->clock->now()),
        ]);
    }
}
