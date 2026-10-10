<?php
declare(strict_types=1);

namespace Radar\Http;

/** Sessão em memória, para testes e para o robô de linha de comando. */
final class ArraySession implements Session
{
    /** @var array<string,mixed> */
    public array $data = [];
    public int $regenerations = 0;
    public bool $destroyed = false;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
    }
}
