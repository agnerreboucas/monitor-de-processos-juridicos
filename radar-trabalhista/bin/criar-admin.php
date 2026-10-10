<?php
declare(strict_types=1);

/**
 * Transforma uma conta existente em administrador da plataforma (o dono do SaaS).
 *   php bin/criar-admin.php email@dono.com
 * Só pode ser feito por quem tem acesso ao servidor: não existe tela nem rota para isso, de propósito.
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Database;
use Radar\Support\Audit;

$email = mb_strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    fwrite(STDERR, "Uso: php bin/criar-admin.php email@dono.com\n");
    exit(2);
}
$pdo = Database::connect(Config::fromEnvironment(__DIR__ . '/../.env'));
$stmt = $pdo->prepare('UPDATE users SET is_platform_admin = 1 WHERE email = ? AND status = \'active\'');
$stmt->execute([$email]);
$ok = $pdo->prepare('SELECT id FROM users WHERE email = ? AND is_platform_admin = 1');
$ok->execute([$email]);
$id = $ok->fetchColumn();
if ($id === false) {
    fwrite(STDERR, "Nenhuma conta ativa com esse e-mail. Crie a conta pela tela de cadastro primeiro.\n");
    exit(1);
}
(new Audit($pdo))->log('platform.admin_granted', null, (int) $id, 'user', (int) $id, 'cli');
echo "Pronto: $email agora é administrador da plataforma. Entre e abra o menu Administração.\n";
