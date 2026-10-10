<?php
declare(strict_types=1);

namespace Radar\Support;

use RuntimeException;

/**
 * Cópia de segurança do banco com mysqldump, compactada. A senha vai por variável de ambiente (nunca na linha de comando,
 * onde qualquer usuário do servidor a veria). Guarda só as N cópias mais recentes.
 */
final class Backup
{
    public function __construct(
        private string $host,
        private int $port,
        private string $database,
        private string $user,
        private string $password,
        private string $directory,
        private string $dumpBinary = 'mysqldump',
    ) {
    }

    /** @return string caminho do arquivo criado */
    public function run(int $keep = 14, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Não consegui criar a pasta de backup.');
        }
        $file = rtrim($this->directory, '/') . '/radar-' . $now->format('Ymd-His') . '.sql.gz';
        $tmp = $file . '.parcial';
        $cmd = [$this->dumpBinary, '--host=' . $this->host, '--port=' . $this->port, '--user=' . $this->user, '--single-transaction',
            '--routines', '--no-tablespaces', '--default-character-set=utf8mb4', $this->database];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => $this->password, 'PATH' => (string) getenv('PATH')]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Não consegui executar o mysqldump.');
        }
        $out = gzopen($tmp, 'wb9');
        if ($out === false) {
            throw new RuntimeException('Não consegui gravar o arquivo de backup.');
        }
        $bytes = 0;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $bytes += strlen($chunk);
            gzwrite($out, $chunk);
        }
        gzclose($out);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || $bytes < 100) {
            @unlink($tmp);
            throw new RuntimeException('mysqldump falhou: ' . trim(preg_replace('/\s+/', ' ', (string) $err) ?? ''));
        }
        // a linha final do mysqldump prova que o arquivo não foi cortado no meio
        $tail = gzfile($tmp);
        if ($tail === false || !str_contains(implode('', array_slice($tail, -3)), 'Dump completed')) {
            @unlink($tmp);
            throw new RuntimeException('O backup saiu incompleto e foi descartado.');
        }
        chmod($tmp, 0600);
        rename($tmp, $file);
        $this->prune($keep);
        return $file;
    }

    private function prune(int $keep): void
    {
        $files = glob(rtrim($this->directory, '/') . '/radar-*.sql.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, $keep)) as $old) {
            @unlink($old);
        }
    }
}
