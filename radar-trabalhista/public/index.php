<?php
declare(strict_types=1);

/**
 * Ponto de entrada. /api/* vai para a aplicação; o resto é a tela (public/app).
 * Em Apache/Hostinger o .htaccess faz isso; para testar localmente:
 *   php -S 127.0.0.1:8080 -t public public/index.php
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Database;
use Radar\Http\App;
use Radar\Http\NativeSession;
use Radar\Http\Request;
use Radar\Mail\MailerFactory;

$request = Request::fromGlobals();

if (!str_starts_with($request->path, '/api/')) {
    $types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon'];
    $file = realpath(__DIR__ . '/app/' . ltrim($request->path === '/' ? 'index.html' : $request->path, '/'));
    $root = realpath(__DIR__ . '/app');
    if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
        $file = $root . '/index.html';                       // a tela usa rotas por #, qualquer outro caminho cai na página inicial
    }
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'");
    readfile($file);
    exit;
}

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$app = new App(
    Database::connect($config),
    new NativeSession($config->get('SESSION_NAME', 'radar_sid'), $request->secure),
    MailerFactory::fromConfig($config, __DIR__ . '/../storage'),
    $config->get('APP_URL'),
    maxFailures: $config->int('LOGIN_MAX_FAILURES', 5),
    lockMinutes: $config->int('LOGIN_LOCK_MINUTES', 15),
);
$app->handle($request)->send();
