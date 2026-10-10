<?php
declare(strict_types=1);

namespace Radar\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, public readonly string $body, public array $headers = [])
    {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function error(string $message, int $status, string $code = 'error'): self
    {
        return self::json(['ok' => false, 'error' => $message, 'code' => $code], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
