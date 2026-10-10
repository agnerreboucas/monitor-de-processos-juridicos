<?php
declare(strict_types=1);

namespace Radar;

use PDO;
use RuntimeException;

/** Aplica, em ordem, os arquivos migrations/NNN_nome.sql que ainda não foram aplicados. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory)
    {
    }

    /** @return list<string> arquivos aplicados nesta chamada */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB');

        $done = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Não consegui ler $version");
            }
            // DDL do MySQL não é transacional: cada comando é aplicado e, se falhar, para aqui.
            foreach ($this->statements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())')
                ->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(array_map('trim', explode(";\n", $sql)), static fn ($s) => $s !== ''));
    }
}
