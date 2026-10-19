<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use PDO;
use Radar\Config;
use Radar\Mail\Mailer;
use Radar\Service\LifecycleNotifier;
use Radar\Service\NotificationDispatcher;
use Radar\Service\PrivacyService;
use Radar\Service\SubscriptionService;
use Radar\Monitoring\DatajudAdapter;

/**
 * Uma rodada completa do robô: fecha testes vencidos, agenda e consulta processos e OABs, envia avisos e e-mails.
 * É o que o cron (bin/worker.php) e o WP-Cron do plugin do WordPress executam.
 */
final class WorkerRun
{
    /**
     * @return array{recovered:int,queued:int,checked:int,ok:int,failed:int,cancelled:int,new_movements:int,oabs:int,oab_failed:int,oab_new:int,emails:int,email_failed:int,lifecycle:int,past_due:int,suspended:int}
     */
    public static function run(PDO $pdo, Config $config, Mailer $mailer, int $maxSeconds = 50, int $batch = 10, int $pauseMs = 300, ?HttpClient $http = null, string $workerId = ''): array
    {
        $http ??= new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null);
        $djenUrl = $config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao');
        $adapters = [new DjenAdapter($http, $djenUrl, $config->int('DJEN_TIMEOUT', 15))];
        if ((string) $config->get('DATAJUD_API_KEY') !== '') {    // andamento completo; sem a chave, só o diário
            $adapters[] = new DatajudAdapter($http, (string) $config->get('DATAJUD_API_KEY'), $config->get('DATAJUD_URL', DatajudAdapter::BASE_URL), $config->int('DATAJUD_TIMEOUT', 20));
        }
        $worker = new Worker($pdo, $adapters, workerId: $workerId !== '' ? $workerId : gethostname() . ':' . getmypid(), pauseBetweenJobsMicros: $pauseMs * 1000);

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
        $djenOab = new DjenAdapter($http, $djenUrl, $config->int('DJEN_TIMEOUT', 15), pageDelayMicros: 500000);
        $oab = (new OabMonitor($pdo, $djenOab, pauseBetweenMicros: $pauseMs * 1000))->runDue(3);

        $mail = (new NotificationDispatcher($pdo, $mailer, $config->get('APP_URL')))->sendPending();
        $lifecycle = (new LifecycleNotifier($pdo, $mailer, $config->get('APP_URL')))->run();      // boas-vindas, marcos do teste e resumo do dia

        $pdo->prepare('UPDATE worker_runs SET finished_at = UTC_TIMESTAMP(), summary = ? WHERE id = ?')->execute([
            json_encode(['checked' => $total['claimed'], 'failed' => $total['failed'], 'new_movements' => $total['new_movements'],
                'oabs' => $oab['claimed'], 'emails' => $mail['emails'] + $lifecycle['sent'], 'past_due' => $lapsed['past_due'], 'suspended' => $lapsed['suspended']]), $runId]);
        $pdo->exec('DELETE FROM worker_runs WHERE started_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        (new PrivacyService($pdo))->purgeExpired();            // prazos de guarda da política de privacidade

        return ['recovered' => $recovered, 'queued' => $queued, 'checked' => $total['claimed'], 'ok' => $total['succeeded'], 'failed' => $total['failed'], 'cancelled' => $total['cancelled'],
            'new_movements' => $total['new_movements'], 'oabs' => $oab['claimed'], 'oab_failed' => $oab['failed'], 'oab_new' => $oab['new_publications'],
            'emails' => $mail['emails'], 'email_failed' => $mail['failed'], 'lifecycle' => $lifecycle['sent'], 'past_due' => $lapsed['past_due'], 'suspended' => $lapsed['suspended']];
    }
}
