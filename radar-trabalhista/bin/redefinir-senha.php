<?php
declare(strict_types=1);

/**
 * Define uma nova senha para uma conta, pelo servidor. Útil enquanto o e-mail (SMTP) não está configurado,
 * porque a recuperação de senha por e-mail ainda não envia nada.
 *   php bin/redefinir-senha.php email@exemplo.com
 * A senha é pedida no terminal (não aparece na tela nem fica no histórico).
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Auth\AuthService;
use Radar\Config;
use Radar\Database;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;

$email = mb_strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    fwrite(STDERR, "Uso: php bin/redefinir-senha.php email@exemplo.com\n");
    exit(2);
}
$pdo = Database::connect(Config::fromEnvironment(__DIR__ . '/../.env'));
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
$stmt->execute([$email]);
$id = $stmt->fetchColumn();
if ($id === false) {
    fwrite(STDERR, "Nenhuma conta ativa com esse e-mail.\n");
    exit(1);
}
$ask = static function (string $label): string {
    fwrite(STDOUT, $label);
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $v = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");
    return $v;
};
$one = $ask('Nova senha (mínimo ' . AuthService::MIN_PASSWORD . ' caracteres): ');
$two = $ask('Repita a senha: ');
if ($one !== $two) {
    fwrite(STDERR, "As senhas não são iguais.\n");
    exit(1);
}
try {
    AuthService::assertPassword($one);
} catch (ValidationError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([AuthService::hashPassword($one), $id]);
$pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
(new Audit($pdo))->log('auth.password_reset_cli', null, (int) $id, 'user', (int) $id, 'cli');
echo "Senha alterada.\n";
