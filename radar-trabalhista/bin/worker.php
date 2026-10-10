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
use Radar\Mail\MailerFactory;
use Radar\Monitoring\OabMonitor;
use Radar\Monitoring\Worker;
use Radar\Service\NotificationDispatcher;
use Radar\Service\PrivacyService;
use Radar\Service\SubscriptionService;

$options = getopt('', ['max-seconds::', 'batch::', 'pause-ms::']);
$maxSeconds = (int) ($options['max-seconds'] ?? 50);
$batch = (int) ($options['batch'] ?? 10);
$pauseMs = (int) ($options['pause-ms'] ?? 300);

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$http = new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null);
$pdo = Database::connect($config);
$worker = new Worker(
    $pdo,
    [new DjenAdapter($http, $config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'), $config->int('DJEN_TIMEOUT', 15))],
    workerId: gethostname() . ':' . getmypid(),
    pauseBetweenJobsMicros: $pauseMs * 1000,
);

$deadline = time() + $maxSeconds;
$pdo->prepare('INSERT INTO worker_runs (started_at) VALUES (UTC_TIMESTAMP())')->execute();
$runId = (int) $pdo->lastInsertId();
$lapsed = (new SubscriptionService($pdo))->tick();      // fecha testes e períodos vencidos antes de agendar consultas
$recovered = $worker->recoverStale();
$queued = $worker->enqueueDue();
$total = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0, 'new_movements' => 0];
do {
    $r = $worker->runBatch($batch);
    foreach ($r as $k => $v) {
        $total[$k] += $v;
    }
} while ($r['claimed'] > 0 && time() < $deadline);

// consulta por OAB: poucas OABs por rodada, com pausa entre páginas (é um serviço público)
$djenOab = new DjenAdapter($http, $config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'), $config->int('DJEN_TIMEOUT', 15), pageDelayMicros: 500000);
$oab = (new OabMonitor($pdo, $djenOab, pauseBetweenMicros: $pauseMs * 1000))->runDue(3);

$mail = (new NotificationDispatcher($pdo, MailerFactory::fromConfig($config, __DIR__ . '/../storage'), $config->get('APP_URL')))->sendPending();

$pdo->prepare('UPDATE worker_runs SET finished_at = UTC_TIMESTAMP(), summary = ? WHERE id = ?')->execute([
    json_encode(['checked' => $total['claimed'], 'failed' => $total['failed'], 'new_movements' => $total['new_movements'],
        'oabs' => $oab['claimed'], 'emails' => $mail['emails'], 'past_due' => $lapsed['past_due'], 'suspended' => $lapsed['suspended']]), $runId]);
$pdo->exec('DELETE FROM worker_runs WHERE started_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
(new PrivacyService($pdo))->purgeExpired();            // prazos de guarda da política de privacidade

printf("[%s] recuperados=%d agendados=%d consultados=%d ok=%d falhas=%d canceladas=%d novas_movimentacoes=%d emails=%d emails_falhos=%d oabs=%d oabs_falhas=%d publicacoes_novas=%d\n",
    date('c'), $recovered, $queued, $total['claimed'], $total['succeeded'], $total['failed'], $total['cancelled'], $total['new_movements'],
    $mail['emails'], $mail['failed'], $oab['claimed'], $oab['failed'], $oab['new_publications']);
