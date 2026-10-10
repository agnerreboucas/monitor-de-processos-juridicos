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

    /**
     * @param list<string> $trustedProxies IPs ou faixas (CIDR) dos proxies reversos (ex.: Caddy/Nginx). Só deles se aceita
     *                                     X-Forwarded-For e X-Forwarded-Proto; de qualquer outro, o cabeçalho é ignorado.
     */
    public static function fromGlobals(array $trustedProxies = []): self
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
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $viaProxy = $remote !== '' && self::ipInList($remote, $trustedProxies);
        $ip = $remote;
        if ($viaProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // o último endereço que NÃO é de proxy nosso é o do visitante (o que vem antes pode ser inventado por ele)
            $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($chain as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                $ip = $candidate;
                if (!self::ipInList($candidate, $trustedProxies)) {
                    break;
                }
            }
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($viaProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return new self(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $_GET, $body, $headers, $ip, $https);
    }

    /** @param list<string> $list IPs ou CIDR (IPv4 e IPv6) */
    public static function ipInList(string $ip, array $list): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($list as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $netPacked = @inet_pton((string) $net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($netPacked[$bytes])) >> (8 - $rest)) === 0) {
                return true;
            }
        }
        return false;
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
