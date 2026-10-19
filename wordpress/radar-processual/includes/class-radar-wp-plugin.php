<?php
if (!defined('ABSPATH')) {
    exit;
}

use Radar\Api\PublicApi;
use Radar\Config;
use Radar\Http\App;
use Radar\Http\ArraySession;
use Radar\Http\NativeSession;
use Radar\Http\Request;
use Radar\Http\Response;
use Radar\Migrator;
use Radar\Monitoring\CurlHttpClient;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\WorkerRun;
use Radar\Service\LookupService;
use Radar\Service\ReadinessService;
use Radar\Support\PrefixedPdo;

/**
 * Liga o Radar ao WordPress:
 *  - /radar/entrar (painel), /radar/api/* (painel), /radar/v1/* (API por chave) e /radar/app/* (arquivos) são atendidos aqui, cedo, antes do tema;
 *  - as tabelas ficam no banco do WordPress com o prefixo "<prefixo>radar_";
 *  - o robô roda pelo WP-Cron (a cada 5 minutos); e-mails saem pelo wp_mail().
 */
final class Radar_WP_Plugin
{
    public const OPTION = 'radar_wp_settings';
    public const SLUG = 'radar';
    private static ?\PDO $pdo = null;

    public static function boot(): void
    {
        add_filter('cron_schedules', static function (array $s): array {
            $s['radar_5min'] = ['interval' => 300, 'display' => 'A cada 5 minutos (Radar)'];
            return $s;
        });
        add_action('radar_wp_tick', [self::class, 'tick']);
        add_action('plugins_loaded', [self::class, 'route'], 1);
        add_action('init', static function (): void {
            if (!wp_next_scheduled('radar_wp_tick')) {
                wp_schedule_event(time() + 60, 'radar_5min', 'radar_wp_tick');
            }
        });
    }

    // ── configuração ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        return (array) get_option(self::OPTION, []) + ['datajud_key' => '', 'djen_url' => 'https://comunicaapi.pje.jus.br/api/v1/comunicacao', 'payment_info' => '',
            'support_contact' => '', 'http_proxy' => '', 'trusted_proxies' => '', 'delete_on_uninstall' => 0];
    }

    /** "/radar" (ou "/subpasta/radar" se o WordPress estiver numa subpasta). */
    public static function basePath(): string
    {
        return rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: ''), '/') . '/' . self::SLUG;
    }

    /** scheme://host[:porta] do site (é o que o navegador manda em "Origin"). */
    public static function origin(): string
    {
        $p = wp_parse_url(home_url('/'));
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function config(): Config
    {
        $s = self::settings();
        $host = (string) DB_HOST;
        $port = '';
        $socket = '';
        if (str_contains($host, ':')) {
            [$h, $rest] = explode(':', $host, 2);
            if ($rest !== '' && $rest[0] === '/') {
                $socket = $rest;
            } elseif (ctype_digit($rest)) {
                $port = $rest;
            }
            $host = $h !== '' ? $h : 'localhost';
        }
        global $wpdb;
        return new Config([
            'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_SOCKET' => $socket, 'DB_NAME' => (string) DB_NAME, 'DB_USER' => (string) DB_USER, 'DB_PASS' => (string) DB_PASSWORD,
            'DB_PREFIX' => $wpdb->prefix . 'radar_',
            'APP_URL' => untrailingslashit(home_url('/' . self::SLUG)), 'APP_ENV' => 'production',
            'TRUSTED_PROXIES' => (string) $s['trusted_proxies'], 'HTTP_PROXY_URL' => (string) $s['http_proxy'],
            'DJEN_URL' => (string) $s['djen_url'], 'DATAJUD_API_KEY' => (string) $s['datajud_key'],
            'PAYMENT_INFO' => (string) $s['payment_info'], 'SUPPORT_CONTACT' => (string) $s['support_contact'],
        ]);
    }

    public static function pdo(): \PDO
    {
        return self::$pdo ??= \Radar\Database::connect(self::config());
    }

    public static function migrate(): array
    {
        $applied = (new Migrator(self::pdo(), RADAR_WP_DIR . 'radar/migrations'))->migrate();
        update_option('radar_wp_db_version', RADAR_WP_VERSION, false);
        return $applied;
    }

    /** @return list<string> nomes das tabelas do Radar, já com prefixo */
    public static function tables(): array
    {
        global $wpdb;
        return array_map(static fn ($t) => $wpdb->prefix . 'radar_' . $t, PrefixedPdo::tablesFrom(RADAR_WP_DIR . 'radar/migrations'));
    }

    // ── ativação ────────────────────────────────────────────────────────────

    public static function activate(): void
    {
        $problems = [];
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            $problems[] = 'PHP 8.2 ou mais (este servidor tem ' . PHP_VERSION . ')';
        }
        foreach (['pdo_mysql', 'curl', 'mbstring', 'json'] as $ext) {
            if (!extension_loaded($ext)) {
                $problems[] = 'a extensão PHP ' . $ext;
            }
        }
        if ($problems !== []) {
            deactivate_plugins(plugin_basename(RADAR_WP_FILE));
            wp_die('O Radar Processual precisa de: ' . esc_html(implode('; ', $problems)) . '. Peça ao suporte da hospedagem e ative de novo.', 'Radar Processual', ['back_link' => true]);
        }
        try {
            self::migrate();
        } catch (\Throwable $e) {
            deactivate_plugins(plugin_basename(RADAR_WP_FILE));
            wp_die('Não consegui criar as tabelas do Radar no banco do WordPress: ' . esc_html($e->getMessage()), 'Radar Processual', ['back_link' => true]);
        }
        add_option(self::OPTION, [], '', false);
        if (!wp_next_scheduled('radar_wp_tick')) {
            wp_schedule_event(time() + 60, 'radar_5min', 'radar_wp_tick');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('radar_wp_tick');
    }

    // ── robô ────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null resultado da rodada, ou null se outra já estava rodando */
    public static function tick(): ?array
    {
        $pdo = self::pdo();
        $lock = $pdo->query("SELECT GET_LOCK('radar_wp_worker', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            return null;        // outra rodada em andamento
        }
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(90);
            }
            $result = WorkerRun::run($pdo, self::config(), new Radar_WP_Mailer(), 45, 10, 300);
            update_option('radar_wp_last_tick', ['at' => time(), 'ok' => true, 'result' => $result], false);
            return $result;
        } catch (\Throwable $e) {
            error_log('[radar] robô: ' . $e::class . ': ' . $e->getMessage());
            update_option('radar_wp_last_tick', ['at' => time(), 'ok' => false, 'error' => $e->getMessage()], false);
            return null;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('radar_wp_worker')");
        }
    }

    // ── rotas ───────────────────────────────────────────────────────────────

    public static function route(): void
    {
        $uri = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = self::basePath();
        if ($uri !== $base && !str_starts_with($uri, $base . '/')) {
            return;                                       // não é do Radar: o WordPress segue o caminho normal
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);              // plugins de cache não guardam o painel nem a API
        }
        $rel = substr($uri, strlen($base)) ?: '/';
        try {
            if (get_option('radar_wp_db_version') !== RADAR_WP_VERSION) {
                self::migrate();                          // plugin atualizado: aplica as migrações novas
            }
            $response = match (true) {
                $rel === '/' => new Response(302, '', ['Location' => $base . '/entrar']),
                $rel === '/entrar' => self::spa($base),
                $rel === '/app/config.js' => self::configJs($base),
                str_starts_with($rel, '/app/') => self::asset(substr($rel, 5), $base),
                str_starts_with($rel, '/api/'), PublicApi::handles($rel) => self::api($base),
                default => new Response(404, 'Não encontrado.', ['Content-Type' => 'text/plain; charset=utf-8']),
            };
        } catch (\Throwable $e) {
            error_log('[radar] ' . $e::class . ': ' . $e->getMessage());
            $response = Response::error('O Radar não está disponível agora. O administrador do site pode ver os detalhes em Configurações > Radar Processual.', 503, 'unavailable');
        }
        $response->send();
        exit;
    }

    private static function api(string $base): Response
    {
        $config = self::config();
        $request = Request::fromGlobals(array_filter(array_map('trim', explode(',', $config->get('TRUSTED_PROXIES')))), $base);
        $pdo = self::pdo();
        $http = new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null);
        $djen = new DjenAdapter($http, $config->get('DJEN_URL'), $config->int('DJEN_TIMEOUT', 15));
        $datajud = $config->get('DATAJUD_API_KEY') !== ''
            ? new DatajudAdapter($http, $config->get('DATAJUD_API_KEY'), DatajudAdapter::BASE_URL, $config->int('DATAJUD_TIMEOUT', 20)) : null;
        $lookup = new LookupService($pdo, $djen, $datajud);
        $upload = wp_upload_dir(null, false);
        $app = new App(
            $pdo,
            PublicApi::handles($request->path) ? new ArraySession() : new NativeSession('radar_sid', $request->secure),      // a API não usa sessão nem cookie
            new Radar_WP_Mailer(),
            $config->get('APP_URL'),
            maxFailures: 5,
            lockMinutes: 15,
            lookup: $lookup,
            publicApi: new PublicApi($pdo, $lookup, $djen, baseUrl: $config->get('APP_URL')),
            readiness: new ReadinessService($pdo, $config, RADAR_WP_DIR . 'radar/app', (string) ($upload['basedir'] ?? sys_get_temp_dir()) . '/radar-backups'),
            paymentInfo: $config->get('PAYMENT_INFO'),
            supportContact: $config->get('SUPPORT_CONTACT'),
            originUrl: self::origin(),
        );
        return $app->handle($request);
    }

    private static function spa(string $base): Response
    {
        $html = (string) file_get_contents(RADAR_WP_DIR . 'radar/app/index.html');
        $html = str_replace(['"/app/analise.js"', '"/app/'], ['"' . $base . '/app/config.js"></script><script src="' . $base . '/app/analise.js"', '"' . $base . '/app/'], $html);
        $html = str_replace('<head>', '<head><meta name="robots" content="noindex,nofollow">', $html);
        return new Response(200, $html, [
            'Content-Type' => 'text/html; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'",
        ]);
    }

    private static function configJs(string $base): Response
    {
        $cfg = ['api' => $base, 'home' => home_url('/'), 'entrar' => $base . '/entrar', 'termos' => $base . '/app/termos.html',
            'privacidade' => $base . '/app/privacidade.html', 'apiDocs' => $base . '/app/api-docs.html', 'v1' => $base . '/v1'];
        return new Response(200, 'window.RADAR_CONFIG = ' . wp_json_encode($cfg) . ';', ['Content-Type' => 'text/javascript; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    private static function asset(string $name, string $base): Response
    {
        $types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
        $root = realpath(RADAR_WP_DIR . 'radar/app');
        $file = realpath(RADAR_WP_DIR . 'radar/app/' . ltrim($name, '/'));
        $type = $file !== false ? ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null) : null;
        if ($root === false || $file === false || $type === null || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)
            || in_array(basename($file), ['index.html', 'inicio.html'], true)) {
            return new Response(404, 'Não encontrado.', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $content = (string) file_get_contents($file);
        if (str_starts_with($type, 'text/html')) {          // páginas como Termos e API: os endereços passam a ficar sob /radar
            $content = str_replace(['"/app/', 'href="/entrar"'], ['"' . $base . '/app/', 'href="' . $base . '/entrar"'], $content);
        }
        return new Response(200, $content, [
            'Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=3600',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'",
        ]);
    }

    // ── dono do sistema ─────────────────────────────────────────────────────

    /** Torna uma conta do Radar (por e-mail) administradora da plataforma. */
    public static function makeOwner(string $email): bool
    {
        $stmt = self::pdo()->prepare("UPDATE users SET is_platform_admin = 1 WHERE email = ? AND status = 'active'");
        $stmt->execute([strtolower(trim($email))]);
        $check = self::pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND is_platform_admin = 1');
        $check->execute([strtolower(trim($email))]);
        return (int) $check->fetchColumn() === 1;
    }
}
