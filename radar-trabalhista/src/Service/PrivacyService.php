<?php
declare(strict_types=1);

namespace Radar\Service;

use DateInterval;
use PDO;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Tenancy\TenantContext;

/**
 * LGPD no nível do escritório: cópia completa dos dados (portabilidade/acesso), exclusão definitiva
 * e limpeza por prazo de guarda. Só o administrador do escritório pede cópia e exclusão.
 */
final class PrivacyService
{
    public const TERMS_VERSION = '2026-10-rascunho';
    /** Quanto tempo cada tipo de registro é guardado (dias). Os mesmos números estão na política de privacidade. */
    public const RETENTION_AUDIT_DAYS = 730;
    public const RETENTION_EVENTS_DAYS = 180;
    public const RETENTION_RESET_DAYS = 7;

    private Audit $audit;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
    }

    /** @return array<string,mixed> tudo do escritório, em JSON, sem senha nem token */
    public function export(TenantContext $ctx, ?string $ip = null): array
    {
        $ctx->require('privacy.manage');
        $t = $ctx->tenantId;
        $all = function (string $sql) use ($t): array {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$t]);
            return $stmt->fetchAll();
        };
        $data = [
            'generated_at' => Clock::sql($this->clock->now()) . ' UTC',
            'office' => $all('SELECT id, name, slug, phone, status, created_at FROM tenants WHERE id = ?')[0] ?? null,
            'subscription' => $all('SELECT s.status, s.current_period_end, p.name AS plan FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = ?')[0] ?? null,
            'people' => $all('SELECT u.name, u.email, m.role, m.status, u.created_at, u.last_login_at, u.terms_accepted_at, u.terms_version
                                FROM tenant_members m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ?'),
            'processes' => $all('SELECT id, cnj, tribunal, client_label, notes, status, created_at, last_success_at FROM processes WHERE tenant_id = ?'),
            'movements' => $all('SELECT process_id, occurred_at, captured_at, kind, description, source_url FROM process_movements WHERE tenant_id = ?'),
            'oabs' => $all('SELECT id, oab_number, oab_uf, lawyer_name, supplementary_ufs, status, created_at FROM oab_registrations WHERE tenant_id = ?'),
            'publications' => $all('SELECT oab_id, cnj, tribunal, uf, published_at, orgao, class_name, description, source_url FROM oab_publications WHERE tenant_id = ?'),
            'audit' => $all('SELECT action, entity_type, entity_id, ip, created_at FROM audit_logs WHERE tenant_id = ?'),
        ];
        $this->audit->log('privacy.exported', $t, $ctx->userId, 'tenant', $t, $ip);
        return $data;
    }

    /**
     * Exclusão DEFINITIVA do escritório e de tudo que é dele. A pessoa digita o nome do escritório e a própria senha.
     * Contas que também pertencem a outro escritório continuam; só o vínculo com este some.
     */
    public function eraseOffice(TenantContext $ctx, string $confirmName, string $password, ?string $ip = null): void
    {
        $ctx->require('privacy.manage');
        $t = $ctx->tenantId;
        $office = $this->pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $office->execute([$t]);
        $name = (string) $office->fetchColumn();
        $hash = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $hash->execute([$ctx->userId]);
        if (trim($confirmName) !== $name) {
            throw new ValidationError('Digite o nome do escritório exatamente como aparece, para confirmar.');
        }
        if (!password_verify($password, (string) $hash->fetchColumn())) {
            throw new ValidationError('Senha incorreta.');
        }

        $this->pdo->beginTransaction();
        try {
            $members = $this->pdo->prepare('SELECT user_id FROM tenant_members WHERE tenant_id = ?');
            $members->execute([$t]);
            $userIds = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
            foreach (['notifications', 'monitoring_events', 'monitoring_jobs', 'process_movements', 'oab_alerts', 'oab_publications', 'processes',
                         'oab_registrations', 'audit_logs', 'tenant_members', 'subscriptions'] as $table) {
                $this->pdo->prepare("DELETE FROM $table WHERE tenant_id = ?")->execute([$t]);
            }
            foreach ($userIds as $userId) {
                $other = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_members WHERE user_id = ?');
                $other->execute([$userId]);
                $admin = $this->pdo->prepare('SELECT is_platform_admin FROM users WHERE id = ?');
                $admin->execute([$userId]);
                if ((int) $other->fetchColumn() === 0 && (int) $admin->fetchColumn() === 0) {        // só estava aqui: a conta também sai
                    $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
                    $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                }
            }
            $this->pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$t]);
            // sobra só este registro, sem dados pessoais, para provar que a exclusão aconteceu
            $this->audit->log('privacy.office_erased', null, null, 'tenant', $t, $ip, ['people' => count($userIds)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Limpeza por prazo de guarda. Seguro de repetir. @return array{audit:int,events:int,resets:int} */
    public function purgeExpired(): array
    {
        $cut = fn (int $days) => Clock::sql($this->clock->now()->sub(new DateInterval('P' . $days . 'D')));
        $run = function (string $sql, string $cutoff): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        };
        return [
            'audit' => $run('DELETE FROM audit_logs WHERE created_at < ?', $cut(self::RETENTION_AUDIT_DAYS)),
            'events' => $run('DELETE FROM monitoring_events WHERE finished_at < ?', $cut(self::RETENTION_EVENTS_DAYS)),
            'resets' => $run('DELETE FROM password_resets WHERE expires_at < ?', $cut(self::RETENTION_RESET_DAYS)),
            'api_cache' => $run('DELETE FROM api_cache WHERE expires_at < ?', Clock::sql($this->clock->now())),
            'api_rate' => (function () {
                $stmt = $this->pdo->prepare('DELETE FROM api_rate WHERE bucket < ?');
                $stmt->execute([intdiv($this->clock->now()->getTimestamp(), 60) - 180]);
                return $stmt->rowCount();
            })(),
        ];
    }
}
