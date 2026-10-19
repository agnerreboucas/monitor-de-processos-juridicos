<?php
declare(strict_types=1);

/**
 * Rotina de monitoramento — rode no cron a cada 5 minutos (ou a cada minuto em servidor próprio), por exemplo:
 *   *\/5 * * * * php /caminho/radar-trabalhista/bin/worker.php >> /var/log/radar-worker.log 2>&1
 *
 * Em cada rodada: recupera trabalho preso, agenda o que está na hora e processa em lotes
 * pequenos até acabar a fila ou o tempo (hospedagem compartilhada tem limite de execução).
 * A lógica fica em Radar\Monitoring\WorkerRun (a mesma que o plugin do WordPress usa).
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Database;
use Radar\Mail\MailerFactory;
use Radar\Monitoring\WorkerRun;

$options = getopt('', ['max-seconds::', 'batch::', 'pause-ms::']);
$config = Config::fromEnvironment(__DIR__ . '/../.env');
$r = WorkerRun::run(
    Database::connect($config),
    $config,
    MailerFactory::fromConfig($config, __DIR__ . '/../storage'),
    (int) ($options['max-seconds'] ?? 50),
    (int) ($options['batch'] ?? 10),
    (int) ($options['pause-ms'] ?? 300),
);

printf("[%s] recuperados=%d agendados=%d consultados=%d ok=%d falhas=%d canceladas=%d novas_movimentacoes=%d emails=%d emails_falhos=%d avisos=%d oabs=%d oabs_falhas=%d publicacoes_novas=%d\n",
    date('c'), $r['recovered'], $r['queued'], $r['checked'], $r['ok'], $r['failed'], $r['cancelled'], $r['new_movements'],
    $r['emails'], $r['email_failed'], $r['lifecycle'], $r['oabs'], $r['oab_failed'], $r['oab_new']);
