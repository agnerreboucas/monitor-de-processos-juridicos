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
use Radar\Monitoring\CurlHttpClient;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\DjenAdapter;
use Radar\Api\PublicApi;
use Radar\Service\LookupService;
use Radar\Http\Request;
use Radar\Mail\MailerFactory;

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$request = Request::fromGlobals(array_filter(array_map('trim', explode(',', $config->get('TRUSTED_PROXIES')))));

if (!str_starts_with($request->path, '/api/') && !PublicApi::handles($request->path)) {
    $types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
    // /  = página de apresentação; /entrar = o sistema (login e telas); /app/arquivo = arquivos estáticos
    $path = $request->path;
    $name = match (true) {
        $path === '/' || $path === '' => 'inicio.html',
        $path === '/entrar' => 'index.html',
        str_starts_with($path, '/app/') => substr($path, 5),
        default => 'index.html',
    };
    $file = realpath(__DIR__ . '/app/' . ltrim($name, '/'));
    $root = realpath(__DIR__ . '/app');
    if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
        $file = $root . '/index.html';
    }
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'");
    readfile($file);
    exit;
}

$pdo = Database::connect($config);
$http = new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null);
$djen = new DjenAdapter($http, $config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'), $config->int('DJEN_TIMEOUT', 15));
$datajud = $config->get('DATAJUD_API_KEY') !== '' && $config->get('DATAJUD_API_KEY') !== null
    ? new DatajudAdapter($http, (string) $config->get('DATAJUD_API_KEY'), $config->get('DATAJUD_URL', DatajudAdapter::BASE_URL), $config->int('DATAJUD_TIMEOUT', 20))
    : null;
$lookup = new LookupService($pdo, $djen, $datajud);
$app = new App(
    $pdo,
    PublicApi::handles($request->path) ? new \Radar\Http\ArraySession() : new NativeSession($config->get('SESSION_NAME', 'radar_sid'), $request->secure),   // a API não usa sessão nem cookie
    MailerFactory::fromConfig($config, __DIR__ . '/../storage'),
    $config->get('APP_URL'),
    maxFailures: $config->int('LOGIN_MAX_FAILURES', 5),
    lockMinutes: $config->int('LOGIN_LOCK_MINUTES', 15),
    lookup: $lookup,
    publicApi: new PublicApi($pdo, $lookup, $djen),
    readiness: new \Radar\Service\ReadinessService($pdo, $config, __DIR__, $config->get('BACKUP_DIR', __DIR__ . '/../storage/backups')),
    paymentInfo: $config->get('PAYMENT_INFO'),
    supportContact: $config->get('SUPPORT_CONTACT'),
);
$app->handle($request)->send();
