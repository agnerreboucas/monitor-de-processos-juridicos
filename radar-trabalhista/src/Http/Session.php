<?php
declare(strict_types=1);

namespace Radar\Http;

interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    /** Novo identificador de sessão (contra fixação de sessão): chamar no login. */
    public function regenerate(): void;

    public function destroy(): void;
}
