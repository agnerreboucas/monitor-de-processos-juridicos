<?php
declare(strict_types=1);

/**
 * Rotina de monitoramento — rode no cron a cada 5 minutos, por exemplo:
 *   *\/5 * * * * php /caminho/radar-trabalhista/bin/worker.php >> /var/log/radar-worker.log 2>&1
 *
 * Em cada rodada: recupera trabalho preso, agenda o que está na hora e processa em lotes
 * pequenos até acabar a fila ou o tempo (hospedagem compartilhada tem limite de execução).
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Database;
use Radar\Monitoring\CurlHttpClient;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\Worker;

$options = getopt('', ['max-seconds::', 'batch::', 'pause-ms::']);
$maxSeconds = (int) ($options['max-seconds'] ?? 50);
$batch = (int) ($options['batch'] ?? 10);
$pauseMs = (int) ($options['pause-ms'] ?? 300);

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$http = new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null);
$worker = new Worker(
    Database::connect($config),
    [new DjenAdapter($http, $config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'), $config->int('DJEN_TIMEOUT', 15))],
    workerId: gethostname() . ':' . getmypid(),
    pauseBetweenJobsMicros: $pauseMs * 1000,
);

$deadline = time() + $maxSeconds;
$recovered = $worker->recoverStale();
$queued = $worker->enqueueDue();
$total = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0, 'new_movements' => 0];
do {
    $r = $worker->runBatch($batch);
    foreach ($r as $k => $v) {
        $total[$k] += $v;
    }
} while ($r['claimed'] > 0 && time() < $deadline);

printf("[%s] recuperados=%d agendados=%d consultados=%d ok=%d falhas=%d canceladas=%d novas_movimentacoes=%d\n",
    date('c'), $recovered, $queued, $total['claimed'], $total['succeeded'], $total['failed'], $total['cancelled'], $total['new_movements']);
