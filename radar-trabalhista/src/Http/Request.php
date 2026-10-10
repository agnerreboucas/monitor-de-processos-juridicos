<?php
declare(strict_types=1);

namespace Radar\Http;

final class Request
{
    /**
     * @param array<string,scalar|null> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers nomes em minúsculas
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly string $ip = '',
        public readonly bool $secure = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = [];
        $raw = file_get_contents('php://input', false, null, 0, 262145) ?: '';
        if ($raw !== '' && strlen($raw) <= 262144) {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : ['__invalid' => true];
        } elseif (strlen($raw) > 262144) {
            $body = ['__invalid' => true];
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $_GET, $body, $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function str(string $key): string
    {
        $v = $this->body[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public function strOrNull(string $key): ?string
    {
        return array_key_exists($key, $this->body) && is_string($this->body[$key]) ? $this->body[$key] : null;
    }

    public function intQuery(string $key, int $default = 0): int
    {
        $v = $this->query[$key] ?? null;
        return is_string($v) && ctype_digit($v) ? (int) $v : $default;
    }

    public function strQuery(string $key): string
    {
        $v = $this->query[$key] ?? '';
        return is_string($v) ? $v : '';
    }
}
