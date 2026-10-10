<?php
declare(strict_types=1);

namespace Radar;

/**
 * Configuração por ambiente (.env ou variáveis de verdade). Nenhum segredo fica no código.
 * Variável de ambiente real tem preferência sobre o arquivo.
 */
final class Config
{
    /** @param array<string,string> $values */
    public function __construct(private array $values = [])
    {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $values = [];
        if ($envFile !== null && is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $values[trim($name)] = trim($value, " \t\"'");
            }
        }
        foreach ($_ENV + getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }
        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';
        return $value !== '' ? $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        return ctype_digit($value) ? (int) $value : $default;
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'production') === 'production';
    }
}
