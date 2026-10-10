<?php
declare(strict_types=1);

namespace Radar\Http;

/** Sessão do PHP com cookie HttpOnly, SameSite e (em HTTPS) Secure. */
final class NativeSession implements Session
{
    public function __construct(private string $name = 'radar_sid', bool $secure = true)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->name);
            session_set_cookie_params([
                'lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');       // não aceita identificador inventado pelo cliente
            ini_set('session.use_only_cookies', '1');
            session_start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
