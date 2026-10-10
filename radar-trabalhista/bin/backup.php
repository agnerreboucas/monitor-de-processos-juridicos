<?php
declare(strict_types=1);

/**
 * Backup do banco. Rode todo dia no cron e COPIE o arquivo para fora do servidor (outro provedor ou armazenamento).
 *   0 3 * * * php /caminho/radar-trabalhista/bin/backup.php >> /var/log/radar-backup.log 2>&1
 * Restaurar:  gunzip -c storage/backups/radar-AAAAMMDD-HHMMSS.sql.gz | mysql -u USUARIO -p NOME_DO_BANCO
 * Um backup que nunca foi restaurado não conta: teste a restauração num banco vazio.
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Support\Backup;

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$keep = (int) (getopt('', ['keep::'])['keep'] ?? 14);
try {
    $file = (new Backup($config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME', 'radar'),
        $config->get('DB_USER', 'radar'), $config->get('DB_PASS'), $config->get('BACKUP_DIR', __DIR__ . '/../storage/backups')))->run($keep);
    printf("[%s] backup ok: %s (%d KB)\n", date('c'), $file, (int) (filesize($file) / 1024));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] BACKUP FALHOU: ' . $e->getMessage() . "\n");
    exit(1);
}
