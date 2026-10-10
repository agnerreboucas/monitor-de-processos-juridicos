<?php
declare(strict_types=1);

namespace Radar\Auth;

use DateInterval;
use PDO;
use Radar\Exception\AuthError;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;

/** Cadastro de escritório, login com bloqueio por tentativas e recuperação de senha. */
final class AuthService
{
    public const MIN_PASSWORD = 10;
    private const RESET_TTL_MINUTES = 60;

    private Audit $audit;

    public function __construct(
        private PDO $pdo,
        private Clock $clock = new Clock(),
        private int $maxFailures = 5,
        private int $lockMinutes = 15,
    ) {
        $this->audit = new Audit($pdo, $clock);
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /**
     * Cria o escritório, o administrador dele e uma assinatura de teste (trial).
     *
     * @return array{tenant_id:int,user_id:int}
     */
    public function registerTenant(string $officeName, string $adminName, string $email, string $password,
                                   string $planCode = 'essencial', ?string $phone = null, ?string $ip = null): array
    {
        $officeName = trim($officeName);
        $adminName = trim($adminName);
        $email = self::normalizeEmail($email);
        if ($officeName === '' || mb_strlen($officeName) > 160) {
            throw new ValidationError('Informe o nome do escritório.');
        }
        if ($adminName === '' || mb_strlen($adminName) > 160) {
            throw new ValidationError('Informe o nome do administrador.');
        }
        self::assertEmail($email);
        self::assertPassword($password);

        $now = Clock::sql($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $plan = $this->pdo->prepare('SELECT id FROM plans WHERE code = ? AND active = 1');
            $plan->execute([$planCode]);
            $planId = $plan->fetchColumn();
            if ($planId === false) {
                throw new ValidationError('Plano inexistente.');
            }
            if ($this->userByEmail($email) !== null) {
                throw new ValidationError('Este e-mail já está cadastrado.');
            }
            $this->pdo->prepare('INSERT INTO tenants (name, slug, phone, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$officeName, $this->uniqueSlug($officeName), $phone, $now]);
            $tenantId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$adminName, $email, self::hashPassword($password), $now]);
            $userId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO tenant_members (tenant_id, user_id, role, status, created_at)
                                 VALUES (?, ?, 'office_admin', 'active', ?)")->execute([$tenantId, $userId, $now]);
            $this->pdo->prepare("INSERT INTO subscriptions (tenant_id, plan_id, status, created_at, updated_at)
                                 VALUES (?, ?, 'trialing', ?, ?)")->execute([$tenantId, $planId, $now, $now]);
            $this->audit->log('tenant.registered', $tenantId, $userId, 'tenant', $tenantId, $ip, ['plan' => $planCode]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['tenant_id' => $tenantId, 'user_id' => $userId];
    }

    /**
     * Confere e-mail e senha. A mensagem de erro é sempre a mesma (não revela se o e-mail existe).
     *
     * @return array{user_id:int,name:string,is_platform_admin:bool,tenants:list<array{tenant_id:int,name:string,role:string}>}
     */
    public function login(string $email, string $password, ?string $ip = null): array
    {
        $email = self::normalizeEmail($email);
        $user = $this->userByEmail($email);
        $now = $this->clock->now();

        if ($user === null) {
            // gasta o mesmo tempo de uma verificação real: não dá para descobrir e-mails pelo tempo de resposta
            password_verify($password, self::dummyHash());
            $this->audit->log('auth.login_failed', null, null, null, null, $ip, ['reason' => 'unknown_email']);
            throw new AuthError();
        }
        $userId = (int) $user['id'];
        if ($user['locked_until'] !== null && $user['locked_until'] > Clock::sql($now)) {
            $this->audit->log('auth.login_blocked', null, $userId, 'user', $userId, $ip);
            throw new AuthError('Muitas tentativas. Aguarde alguns minutos e tente de novo.');
        }
        if ($user['status'] !== 'active' || !password_verify($password, (string) $user['password_hash'])) {
            $failures = (int) $user['failed_logins'] + 1;
            $lockedUntil = $failures >= $this->maxFailures
                ? Clock::sql($now->add(new DateInterval('PT' . $this->lockMinutes . 'M'))) : null;
            $this->pdo->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
                ->execute([$lockedUntil !== null ? 0 : $failures, $lockedUntil, $userId]);
            $this->audit->log('auth.login_failed', null, $userId, 'user', $userId, $ip,
                ['locked' => $lockedUntil !== null]);
            throw new AuthError();
        }

        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([self::hashPassword($password), $userId]);
        }
        $this->pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?')
            ->execute([Clock::sql($now), $userId]);
        $this->audit->log('auth.login', null, $userId, 'user', $userId, $ip);

        $stmt = $this->pdo->prepare(
            "SELECT t.id AS tenant_id, t.name, m.role FROM tenant_members m JOIN tenants t ON t.id = m.tenant_id
              WHERE m.user_id = ? AND m.status = 'active' AND t.status = 'active' ORDER BY t.name"
        );
        $stmt->execute([$userId]);
        return [
            'user_id' => $userId,
            'name' => (string) $user['name'],
            'is_platform_admin' => (bool) $user['is_platform_admin'],
            'tenants' => array_map(static fn (array $r) => [
                'tenant_id' => (int) $r['tenant_id'], 'name' => (string) $r['name'], 'role' => (string) $r['role'],
            ], $stmt->fetchAll()),
        ];
    }

    /**
     * Gera um token de recuperação (válido por 60 min, uso único). Só o hash fica no banco.
     * Devolve null quando o e-mail não existe: quem chama responde igual nos dois casos.
     */
    public function requestPasswordReset(string $email, ?string $ip = null): ?string
    {
        $user = $this->userByEmail(self::normalizeEmail($email));
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
            ->execute([(int) $user['id'], hash('sha256', $token),
                Clock::sql($now->add(new DateInterval('PT' . self::RESET_TTL_MINUTES . 'M'))), Clock::sql($now)]);
        $this->audit->log('auth.reset_requested', null, (int) $user['id'], 'user', (int) $user['id'], $ip);
        return $token;
    }

    public function resetPassword(string $token, string $newPassword, ?string $ip = null): void
    {
        self::assertPassword($newPassword);
        $now = Clock::sql($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $token), $now]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new ValidationError('Link inválido ou expirado. Peça um novo.');
            }
            $this->pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?')->execute([$now, $row['id']]);
            $this->pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
                ->execute([self::hashPassword($newPassword), $row['user_id']]);
            // invalida os outros links ainda abertos desse usuário
            $this->pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                ->execute([$now, $row['user_id']]);
            $this->audit->log('auth.password_reset', null, (int) $row['user_id'], 'user', (int) $row['user_id'], $ip);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function assertEmail(string $email): void
    {
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationError('E-mail inválido.');
        }
    }

    public static function assertPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new ValidationError('A senha precisa ter ao menos ' . self::MIN_PASSWORD . ' caracteres.');
        }
        if (strlen($password) > 1000) {
            throw new ValidationError('Senha longa demais.');
        }
    }

    /** @return array<string,mixed>|null */
    private function userByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
        $base = substr($base !== '' ? $base : 'escritorio', 0, 60);
        $slug = $base;
        for ($i = 2; $this->slugTaken($slug); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    private function slugTaken(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() !== false;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= self::hashPassword('senha-que-nunca-sera-usada');
    }
}
