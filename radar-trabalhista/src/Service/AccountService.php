<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Auth\AuthService;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;

/** A conta da própria pessoa logada (nome, senha, preferência de aviso). Nunca mexe na conta de outra pessoa. */
final class AccountService
{
    private Audit $audit;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
        $this->audit = new Audit($pdo, $clock);
    }

    /** @return array{name:string,email:string,email_notifications:bool,terms_accepted_at:?string,terms_version:?string} */
    public function get(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT name, email, email_notifications, terms_accepted_at, terms_version FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFound('Conta não encontrada.');
        }
        $row['email_notifications'] = (bool) $row['email_notifications'];
        return $row;
    }

    public function update(int $userId, ?string $name, ?bool $emailNotifications, ?string $ip = null): void
    {
        $sets = [];
        $params = [];
        if ($name !== null) {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 160) {
                throw new ValidationError('Informe o seu nome.');
            }
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($emailNotifications !== null) {
            $sets[] = 'email_notifications = ?';
            $params[] = $emailNotifications ? 1 : 0;
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute([...$params, $userId]);
        $this->audit->log('account.updated', null, $userId, 'user', $userId, $ip);
    }

    public function changePassword(int $userId, string $current, string $new, ?string $ip = null): void
    {
        $since = Clock::sql($this->clock->now()->modify('-15 minutes'));
        $failed = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'account.password_failed' AND user_id = ? AND created_at >= ?");
        $failed->execute([$userId, $since]);
        if ((int) $failed->fetchColumn() >= 5) {                       // ninguém fica testando senhas pela tela de troca
            throw new LimitExceeded('Muitas tentativas. Aguarde alguns minutos.');
        }
        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false) {
            throw new NotFound('Conta não encontrada.');
        }
        if (!password_verify($current, (string) $hash)) {
            $this->audit->log('account.password_failed', null, $userId, 'user', $userId, $ip);
            throw new ValidationError('A senha atual não confere.');
        }
        AuthService::assertPassword($new);
        if ($new === $current) {
            throw new ValidationError('A nova senha precisa ser diferente da atual.');
        }
        $now = Clock::sql($this->clock->now());
        $this->pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([AuthService::hashPassword($new), $userId]);
        $this->pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([$now, $userId]);
        $this->audit->log('account.password_changed', null, $userId, 'user', $userId, $ip);
    }
}
