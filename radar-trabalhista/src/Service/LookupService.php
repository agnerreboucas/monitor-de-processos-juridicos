<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Exception\ValidationError;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\InvalidResponse;
use Radar\Monitoring\SourceUnavailable;
use Radar\Support\Cnj;
use Radar\Support\Tribunals;
use Radar\Tenancy\TenantContext;

/**
 * Consulta avulsa: o usuário digita um número CNJ e vê agora a capa e TODO o andamento, juntando o
 * Datajud (andamento completo) e o DJEN (publicações do diário). Nada é gravado; para acompanhar,
 * o processo é cadastrado pelo caminho normal. Cada fonte que falha aparece como falha (nunca como
 * "sem andamento"): o usuário precisa saber que o quadro está incompleto.
 */
final class LookupService
{
    public function __construct(private PDO $pdo, private ?DjenAdapter $djen, private ?DatajudAdapter $datajud)
    {
    }

    /** @return array<string,mixed> */
    public function lookup(TenantContext $ctx, string $input): array
    {
        $ctx->require('process.view');
        $cnj = Cnj::parse($input) ?? throw new ValidationError('Número fora do padrão CNJ. Confira os dígitos (0000000-00.0000.0.00.0000).');

        $timeline = [];
        $sources = [];
        $case = null;

        if ($this->datajud === null) {
            $sources[] = ['code' => 'datajud', 'name' => 'Datajud (CNJ) — andamento completo', 'status' => 'not_configured', 'count' => 0,
                'message' => 'Não configurado: falta a chave DATAJUD_API_KEY no arquivo .env do servidor.'];
        } else {
            try {
                $found = $this->datajud->lookup($cnj);
                if ($found === null) {
                    $sources[] = ['code' => 'datajud', 'name' => 'Datajud (CNJ) — andamento completo', 'status' => 'not_found', 'count' => 0,
                        'message' => 'O Datajud não tem este processo (pode ser sigiloso, muito recente ou de outro tribunal).'];
                } else {
                    $case = array_diff_key($found, ['movements' => 1]);
                    foreach ($found['movements'] as $m) {
                        $timeline[] = $this->row('datajud', $m->occurredAt, (string) ($m->metadata['hora'] ?? ''), $m->kind, $m->description, null);
                    }
                    $sources[] = ['code' => 'datajud', 'name' => 'Datajud (CNJ) — andamento completo', 'status' => 'ok', 'count' => count($found['movements']), 'message' => null];
                }
            } catch (SourceUnavailable | InvalidResponse $e) {
                $sources[] = ['code' => 'datajud', 'name' => 'Datajud (CNJ) — andamento completo', 'status' => 'error', 'count' => 0, 'message' => $e->getMessage()];
            }
        }

        if ($this->djen === null) {
            $sources[] = ['code' => 'djen', 'name' => 'Diário (DJEN) — publicações', 'status' => 'not_configured', 'count' => 0, 'message' => 'Fonte não configurada.'];
        } else {
            try {
                $items = $this->djen->fetch($cnj, null);
                foreach ($items as $m) {
                    $timeline[] = $this->row('djen', $m->occurredAt, '', $m->kind, $m->description, $m->sourceUrl);
                }
                $sources[] = ['code' => 'djen', 'name' => 'Diário (DJEN) — publicações', 'status' => 'ok', 'count' => count($items), 'message' => null];
            } catch (SourceUnavailable | InvalidResponse $e) {
                $sources[] = ['code' => 'djen', 'name' => 'Diário (DJEN) — publicações', 'status' => 'error', 'count' => 0, 'message' => $e->getMessage()];
            }
        }

        usort($timeline, fn (array $a, array $b) => [$b['date'] ?? '', $b['time'], $b['source']] <=> [$a['date'] ?? '', $a['time'], $a['source']]);

        $monitored = $this->pdo->prepare("SELECT id FROM processes WHERE tenant_id = ? AND cnj_digits = ? AND status <> 'archived' LIMIT 1");
        $monitored->execute([$ctx->tenantId, $cnj->digits]);
        $id = $monitored->fetchColumn();

        return [
            'cnj' => $cnj->formatted(), 'tribunal' => $cnj->tribunal(), 'segment' => $cnj->segmentName(), 'uf' => Tribunals::uf($cnj->tribunal()),
            'case' => $case, 'sources' => $sources, 'timeline' => $timeline,
            'monitored_id' => $id === false ? null : (int) $id,
        ];
    }

    /** @return array<string,mixed> */
    private function row(string $source, ?string $date, string $time, string $kind, string $text, ?string $url): array
    {
        return ['source' => $source, 'date' => $date, 'time' => $time, 'kind' => $kind, 'text' => $text,
            'url' => $url !== null && str_starts_with($url, 'https://') ? $url : null];
    }
}
