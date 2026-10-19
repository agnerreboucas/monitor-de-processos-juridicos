<?php
declare(strict_types=1);

namespace Radar\Support;

use PDO;
use PDOStatement;

/**
 * PDO que coloca um prefixo (ex.: "wp_radar_") nas tabelas do Radar, para conviver com as tabelas de outro sistema
 * (o WordPress) no mesmo banco. Só mexe em nomes de tabela conhecidos e só FORA de textos entre aspas simples,
 * então valores como 'plans' dentro de uma consulta não são alterados. Sem prefixo, nada muda.
 */
final class PrefixedPdo extends PDO
{
    private string $pattern;
    /** @var array<string,string> */
    private array $cache = [];

    /**
     * @param list<string> $tables nomes de tabela do Radar (sem prefixo)
     * @param array<int,mixed> $options
     */
    public function __construct(string $dsn, ?string $username, ?string $password, array $options, private string $prefix, array $tables)
    {
        parent::__construct($dsn, $username, $password, $options);
        usort($tables, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $this->pattern = '/(?<![\w`.$])(' . implode('|', array_map(static fn ($t) => preg_quote($t, '/'), $tables)) . ')(?![\w$])/';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** Aplica o prefixo às tabelas fora de textos entre aspas. */
    public function rewrite(string $sql): string
    {
        if (isset($this->cache[$sql])) {
            return $this->cache[$sql];
        }
        $parts = preg_split("/('(?:[^'\\\\]|\\\\.|'')*')/s", $sql, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$sql];
        $out = '';
        foreach ($parts as $part) {
            $out .= $part[0] === "'" ? $part : $this->prefixTables($part);
        }
        return $this->cache[$sql] = $out;
    }

    /** Prefixa os nomes de tabela, menos quando são apelido (AS processes) ou coluna. */
    private function prefixTables(string $sql): string
    {
        return (string) preg_replace_callback($this->pattern, function (array $m) use ($sql): string {
            $before = substr($sql, 0, (int) $m[0][1]);
            return preg_match('/\bAS\s+$/i', $before) === 1 ? $m[0][0] : $this->prefix . $m[0][0];
        }, $sql, -1, $count, PREG_OFFSET_CAPTURE);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewrite($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->rewrite($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /** Nomes de tabela criados pelas migrações (CREATE TABLE ...). @return list<string> */
    public static function tablesFrom(string $migrationsDir): array
    {
        $tables = ['schema_migrations'];
        foreach (glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [] as $file) {
            if (preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', (string) file_get_contents($file), $m)) {
                array_push($tables, ...$m[1]);
            }
        }
        return array_values(array_unique($tables));
    }
}
