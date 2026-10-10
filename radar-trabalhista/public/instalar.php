<?php
declare(strict_types=1);

/**
 * Assistente de instalação e diagnóstico para hospedagem compartilhada (sem terminal).
 * Só funciona se o .env tiver INSTALL_TOKEN preenchido. Quando terminar, APAGUE essa linha do .env:
 * a página volta a responder como se não existisse. Nada aqui mostra senhas.
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Auth\AuthService;
use Radar\Config;
use Radar\Database;
use Radar\Mail\MailerException;
use Radar\Mail\MailerFactory;
use Radar\Migrator;
use Radar\Monitoring\CurlHttpClient;
use Radar\Support\Audit;

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$root = dirname(__DIR__);
$config = Config::fromEnvironment($root . '/.env');
$token = $config->get('INSTALL_TOKEN');
if ($token === '') {
    http_response_code(404);
    echo '<!doctype html><title>Não encontrado</title><h1>Não encontrado</h1>';
    exit;
}
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// limite de tentativas erradas (arquivo na pasta storage), para ninguém ficar adivinhando o código
$tries = $root . '/storage/instalar-tentativas.json';
$recent = [];
if (is_file($tries)) {
    $recent = array_values(array_filter((array) json_decode((string) file_get_contents($tries), true), static fn ($t) => is_int($t) && $t > time() - 3600));
}
$given = (string) ($_POST['codigo'] ?? '');
$logged = false;
if ($given !== '') {
    if (count($recent) >= 10) {
        http_response_code(429);
        exit('Muitas tentativas. Aguarde uma hora.');
    }
    if (hash_equals($token, $given)) {
        $logged = true;
    } else {
        $recent[] = time();
        @file_put_contents($tries, json_encode($recent));
        sleep(1);
    }
}
$page = static function (string $body) use ($e): void {
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação do Radar</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:860px;margin:24px auto;padding:0 16px;color:#14212b;background:#f4f6f8}h1{font-size:24px}h2{font-size:17px;margin:26px 0 8px}'
        . '.c{background:#fff;border:1px solid #e3e8ec;border-radius:12px;padding:16px;margin:10px 0}.ok{color:#0a7a5a}.no{color:#c4281c}.av{color:#a96d00}code,pre{background:#eef1f3;padding:2px 6px;border-radius:6px;word-break:break-all}pre{padding:10px;overflow:auto}'
        . 'input,button{font:inherit;padding:8px 10px;border:1px solid #cfd8dd;border-radius:8px}button{background:#e8a21a;border-color:#e8a21a;font-weight:700;cursor:pointer}table{border-collapse:collapse;width:100%}td{padding:6px 4px;border-top:1px solid #e3e8ec;vertical-align:top}</style>'
        . '<h1>Instalação do Radar Trabalhista</h1>' . $body . '</html>';
};
if (!$logged) {
    $page('<div class="c"><form method="post"><p>Digite o código de instalação (o valor de <code>INSTALL_TOKEN</code> do arquivo <code>.env</code>).</p>'
        . '<input type="password" name="codigo" autocomplete="off" required> <button>Entrar</button></form></div>');
    exit;
}

$msg = '';
$hidden = '<input type="hidden" name="codigo" value="' . $e($token) . '">';
$action = (string) ($_POST['acao'] ?? '');
$dbError = null;
$pdo = null;
try {
    $pdo = Database::connect($config);
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

if ($action === 'migrar' && $pdo !== null) {
    try {
        $done = (new Migrator($pdo, $root . '/migrations'))->migrate();
        $msg = '<p class="ok">Tabelas conferidas. Aplicadas agora: ' . ($done === [] ? 'nenhuma (já estava tudo em dia)' : $e(implode(', ', $done))) . '.</p>';
    } catch (Throwable $ex) {
        $msg = '<p class="no">Falhou: ' . $e($ex->getMessage()) . '</p>';
    }
}
if ($action === 'admin' && $pdo !== null) {
    $email = AuthService::normalizeEmail((string) ($_POST['email'] ?? ''));
    $stmt = $pdo->prepare("UPDATE users SET is_platform_admin = 1 WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $id = $pdo->prepare('SELECT id FROM users WHERE email = ? AND is_platform_admin = 1');
    $id->execute([$email]);
    $uid = $id->fetchColumn();
    if ($uid === false) {
        $msg = '<p class="no">Nenhuma conta ativa com esse e-mail. Crie a conta primeiro pela tela de cadastro.</p>';
    } else {
        (new Audit($pdo))->log('platform.admin_granted', null, (int) $uid, 'user', (int) $uid, 'instalar.php');
        $msg = '<p class="ok">Pronto: ' . $e($email) . ' agora é administrador da plataforma.</p>';
    }
}
if ($action === 'email') {
    $to = trim((string) ($_POST['para'] ?? ''));
    try {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('E-mail inválido.');
        }
        MailerFactory::fromConfig($config, $root . '/storage')->send($to, '', 'Radar Trabalhista: teste de e-mail', "Se você recebeu esta mensagem, o envio de e-mail está funcionando.\n");
        $msg = '<p class="ok">E-mail enviado para ' . $e($to) . '. Confira a caixa de entrada e o spam.</p>';
    } catch (MailerException | RuntimeException $ex) {
        $msg = '<p class="no">Não enviou: ' . $e($ex->getMessage()) . '</p>';
    }
}

$rows = [];
$add = static function (string $name, string $state, string $detail) use (&$rows): void {
    $rows[] = [$name, $state, $detail];
};
$add('Versão do PHP', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'no', PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : ' (precisa de 8.2 ou mais: troque no hPanel, em Avançado > Configuração do PHP)'));
foreach (['pdo_mysql', 'curl', 'mbstring', 'json', 'openssl'] as $ext) {
    $add("Extensão $ext", extension_loaded($ext) ? 'ok' : 'no', extension_loaded($ext) ? 'ativa' : 'desativada (ative no hPanel)');
}
$add('Pasta storage gravável', is_writable($root . '/storage') ? 'ok' : 'no', $root . '/storage');
$add('Endereço do sistema (APP_URL)', str_starts_with($config->get('APP_URL'), 'https://') ? 'ok' : 'av', $config->get('APP_URL') ?: 'não preenchido');
$add('Ambiente (APP_ENV)', $config->isProduction() ? 'ok' : 'av', $config->get('APP_ENV', 'production') . ($config->isProduction() ? '' : ' (mude para production antes de abrir ao público)'));
if ($pdo === null) {
    $add('Banco de dados', 'no', 'Não conectou: ' . (string) $dbError . '. Confira DB_HOST, DB_NAME, DB_USER e DB_PASS no .env.');
} else {
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $maria = stripos($version, 'mariadb') !== false;
    preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $v);
    $okVersion = $maria ? version_compare($v[0] ?? '0', '10.6.0', '>=') : version_compare($v[0] ?? '0', '8.0.1', '>=');
    $add('Banco de dados', $okVersion ? 'ok' : 'no', $version . ($okVersion ? '' : ' (precisa de MariaDB 10.6+ ou MySQL 8+)'));
    $applied = 0;
    $all = count(glob($root . '/migrations/*.sql') ?: []);
    try {
        $applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    } catch (Throwable) {
    }
    $add('Tabelas do sistema', $applied >= $all ? 'ok' : 'av', "$applied de $all aplicadas" . ($applied >= $all ? '' : ': clique em "Criar/atualizar tabelas" abaixo'));
    if ($applied >= $all) {
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $admins = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1')->fetchColumn();
        $add('Contas', $users > 0 ? 'ok' : 'av', "$users conta(s), $admins administradora(s) da plataforma");
        $last = $pdo->query('SELECT started_at FROM worker_runs ORDER BY id DESC LIMIT 1')->fetchColumn();
        $stale = $last === false || strtotime((string) $last . ' UTC') < time() - 20 * 60;
        $add('Robô (cron)', $stale ? 'av' : 'ok', $last === false ? 'ainda não rodou nenhuma vez: configure a tarefa agendada (veja abaixo)' : 'última rodada: ' . $last . ' UTC' . ($stale ? ' (parado há mais de 20 min)' : ''));
    }
}
$smtp = $config->get('SMTP_HOST');
$add('E-mail (SMTP)', $smtp !== '' ? 'ok' : 'av', $smtp !== '' ? $smtp . ':' . $config->get('SMTP_PORT', '587') : 'não configurado: convites e avisos por e-mail não saem');

// fonte de dados: um pedido de verdade ao DJEN, para saber se esta hospedagem é aceita
$djenResult = null;
if ($action === 'djen') {
    $http = new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null);
    $r = $http->get($config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'),
        ['numeroProcesso' => '00014578520265080106', 'tamanhoPagina' => 1, 'pagina' => 1], ['Accept' => 'application/json', 'User-Agent' => 'RadarTrabalhista/1.0'], 20);
    if ($r->status === 200 && is_array(json_decode($r->body, true))) {
        $djenResult = ['ok', 'O DJEN respondeu normalmente (HTTP 200). Esta hospedagem consegue consultar a fonte.'];
    } elseif ($r->status === 403) {
        $djenResult = ['no', 'O DJEN recusou este servidor (HTTP 403). O IP desta hospedagem não é aceito. Veja a opção de proxy no guia, ou use outra hospedagem.'];
    } else {
        $djenResult = ['no', 'Sem resposta útil: HTTP ' . $r->status . ($r->error ? ' (' . $r->error . ')' : '') . '.'];
    }
}

$cron = [];
foreach (['/usr/bin/php', '/usr/local/bin/php', '/opt/alt/php84/usr/bin/php', '/opt/alt/php83/usr/bin/php', '/opt/alt/php82/usr/bin/php'] as $bin) {
    if (@is_executable($bin)) {
        $cron[] = $bin;
    }
}
$state = ['ok' => '<span class="ok">OK</span>', 'no' => '<span class="no">PROBLEMA</span>', 'av' => '<span class="av">ATENÇÃO</span>'];
$body = $msg . '<div class="c"><table>';
foreach ($rows as [$n, $s, $d]) {
    $body .= '<tr><td>' . $e($n) . '</td><td>' . $state[$s] . '</td><td>' . $e($d) . '</td></tr>';
}
$body .= '</table></div>';
if ($djenResult !== null) {
    $body .= '<div class="c"><p class="' . $djenResult[0] . '">' . $e($djenResult[1]) . '</p></div>';
}
$body .= '<h2>Ações</h2><div class="c"><form method="post" style="display:inline">' . $hidden . '<input type="hidden" name="acao" value="migrar"><button>Criar/atualizar tabelas</button></form> '
    . '<form method="post" style="display:inline">' . $hidden . '<input type="hidden" name="acao" value="djen"><button>Testar a fonte de dados (DJEN)</button></form></div>'
    . '<div class="c"><form method="post">' . $hidden . '<input type="hidden" name="acao" value="admin"><p>Tornar uma conta administradora da plataforma (crie a conta pela tela de cadastro antes):</p>'
    . '<input type="email" name="email" placeholder="seu@email.com" required> <button>Tornar administradora</button></form></div>'
    . '<div class="c"><form method="post">' . $hidden . '<input type="hidden" name="acao" value="email"><p>Enviar e-mail de teste:</p><input type="email" name="para" placeholder="seu@email.com" required> <button>Enviar</button></form></div>'
    . '<h2>Tarefa agendada (cron) do robô</h2><div class="c"><p>No hPanel, abra <b>Avançado > Cron Jobs</b>, escolha "a cada 5 minutos" e use um comando assim:</p><pre>'
    . $e(($cron[0] ?? '/usr/bin/php') . ' ' . $root . '/bin/worker.php') . '</pre>'
    . '<p class="av">Programas PHP encontrados neste servidor: ' . $e($cron === [] ? 'nenhum nos caminhos conhecidos. Teste o comando e veja a versão com php -v' : implode(', ', $cron)) . '. O PHP da linha de comando precisa ser 8.2 ou mais.</p></div>'
    . '<div class="c"><p class="av"><b>Quando terminar:</b> apague a linha <code>INSTALL_TOKEN</code> do arquivo <code>.env</code>. Esta página deixa de existir.</p></div>';
$page($body);
