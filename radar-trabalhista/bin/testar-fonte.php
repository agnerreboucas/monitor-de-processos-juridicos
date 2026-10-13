<?php
declare(strict_types=1);

/**
 * Primeiro teste com dados REAIS, sem banco e sem tela. Rode num computador/servidor no Brasil:
 *   php bin/testar-fonte.php processo 0001457-85.2026.5.08.0106   (Diário + Datajud, se DATAJUD_API_KEY estiver no .env)
 *   php bin/testar-fonte.php oab 12345 MG [dias=30]
 * Mostra exatamente o que o DJEN devolveu. Nada é gravado.
 */
require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Monitoring\CurlHttpClient;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\DjenAdapter;
use Radar\Support\Cnj;

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$djen = new DjenAdapter(new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null),
    $config->get('DJEN_URL', 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'), $config->int('DJEN_TIMEOUT', 15));

$modo = $argv[1] ?? '';
$datajudKey = $config->get('DATAJUD_API_KEY');
try {
    if ($modo === 'processo' && isset($argv[2])) {
        $cnj = Cnj::parse($argv[2]) ?? throw new InvalidArgumentException('Número CNJ inválido (confira os dígitos).');
        echo "Processo {$cnj->formatted()} ({$cnj->tribunal()})\n";
        $items = $djen->fetch($cnj, null);
        printf("%d movimentação(ões) no diário.\n\n", count($items));
        foreach (array_slice($items, 0, 10) as $m) {
            printf("- %s | %s\n  %s\n", $m->occurredAt ?? 'sem data', $m->kind, mb_substr(preg_replace('/\s+/', ' ', $m->description) ?? '', 0, 200));
        }
        if ($datajudKey === '') {
            echo "\nDatajud: pulado (falta DATAJUD_API_KEY no .env; sem ela não há o andamento completo).\n";
        } else {
            $datajud = new DatajudAdapter(new CurlHttpClient($config->get('HTTP_PROXY_URL') ?: null), $datajudKey, $config->get('DATAJUD_URL', DatajudAdapter::BASE_URL), $config->int('DATAJUD_TIMEOUT', 20));
            $caso = $datajud->lookup($cnj);
            if ($caso === null) {
                echo "\nDatajud: o processo não está na base (sigiloso, muito recente ou número de outro tribunal).\n";
            } else {
                printf("\nDatajud: %s | %s | %s | %d movimento(s)\n", $caso['classe'], $caso['orgao'], $caso['grau'], count($caso['movements']));
                $todos = $caso['movements'];
                usort($todos, fn ($a, $b) => strcmp((string) $b->occurredAt . ($b->metadata['hora'] ?? ''), (string) $a->occurredAt . ($a->metadata['hora'] ?? '')));
                foreach (array_slice($todos, 0, 15) as $m) {
                    printf("- %s %s | %s\n", $m->occurredAt, $m->metadata['hora'] ?? '', mb_substr($m->description, 0, 160));
                }
            }
        }
    } elseif ($modo === 'oab' && isset($argv[2], $argv[3])) {
        $dias = max(1, min(120, (int) ($argv[4] ?? 30)));
        $hoje = new DateTimeImmutable('today');
        $items = $djen->fetchByOab($argv[2], $argv[3], $hoje->modify("-$dias days"), $hoje);
        printf("OAB %s/%s: %d publicação(ões) nos últimos %d dias.\n\n", $argv[2], strtoupper($argv[3]), count($items), $dias);
        foreach (array_slice($items, 0, 10) as $p) {
            printf("- %s | %s | %s\n  %s\n", $p->publishedAt ?? 'sem data', (Cnj::parse($p->cnjDigits)?->formatted() ?? $p->cnjDigits), $p->tribunal,
                mb_substr(preg_replace('/\s+/', ' ', $p->description) ?? '', 0, 200));
        }
    } else {
        fwrite(STDERR, "Uso:\n  php bin/testar-fonte.php processo 0000000-00.0000.0.00.0000\n  php bin/testar-fonte.php oab 12345 MG [dias]\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "FALHOU: " . $e->getMessage() . "\n");
    exit(1);
}
