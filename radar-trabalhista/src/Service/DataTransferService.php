<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Exception\LimitExceeded;
use Radar\Exception\ValidationError;
use Radar\Support\Audit;
use Radar\Support\Clock;
use Radar\Support\Cnj;
use Radar\Support\Tribunals;
use Radar\Tenancy\TenantContext;

/** Importação de processos por planilha (CSV) e exportação de processos e publicações. */
final class DataTransferService
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 1_000_000;

    public function __construct(private PDO $pdo, private Clock $clock = new Clock())
    {
    }

    /**
     * Cada linha vira um processo, com o relatório do que aconteceu. Uma linha ruim NÃO derruba as outras.
     * Colunas aceitas (com ou sem cabeçalho): número do processo; cliente (opcional). Separador ; ou , ou tabulação.
     *
     * @return array{created:int,skipped:list<array{line:int,cnj:string,reason:string}>,stopped_by_limit:bool}
     */
    public function importProcesses(TenantContext $ctx, string $csv, ?string $ip = null): array
    {
        $ctx->require('process.create');
        if (strlen($csv) > self::MAX_BYTES) {
            throw new ValidationError('Arquivo grande demais (limite de 1 MB).');
        }
        $rows = self::parse($csv);
        if ($rows === []) {
            throw new ValidationError('O arquivo está vazio.');
        }
        if (self::looksLikeHeader($rows[0])) {
            array_shift($rows);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new ValidationError('Máximo de ' . self::MAX_ROWS . ' linhas por importação. Divida o arquivo.');
        }
        $processes = new ProcessService($this->pdo, $this->clock);
        $created = 0;
        $skipped = [];
        $seen = [];
        $stopped = false;
        foreach ($rows as $i => $row) {
            $line = $i + 2;                                   // 1 = cabeçalho; mantém a numeração da planilha
            $raw = trim((string) ($row[0] ?? ''));
            if ($raw === '' && trim((string) ($row[1] ?? '')) === '') {
                continue;
            }
            if ($stopped) {
                $skipped[] = ['line' => $line, 'cnj' => $raw, 'reason' => 'Não importado: limite do plano atingido.'];
                continue;
            }
            $cnj = Cnj::parse($raw);
            if ($cnj === null) {
                $skipped[] = ['line' => $line, 'cnj' => mb_substr($raw, 0, 40), 'reason' => 'Número inválido (confira os dígitos).'];
                continue;
            }
            if (isset($seen[$cnj->digits])) {
                $skipped[] = ['line' => $line, 'cnj' => $cnj->formatted(), 'reason' => 'Repetido no próprio arquivo.'];
                continue;
            }
            $seen[$cnj->digits] = true;
            try {
                $processes->create($ctx, $cnj->formatted(), trim((string) ($row[1] ?? '')) ?: null, $ctx->userId, null, $ip);
                $created++;
            } catch (LimitExceeded $e) {
                $stopped = true;
                $skipped[] = ['line' => $line, 'cnj' => $cnj->formatted(), 'reason' => $e->getMessage()];
            } catch (ValidationError $e) {
                $skipped[] = ['line' => $line, 'cnj' => $cnj->formatted(), 'reason' => $e->getMessage()];
            }
        }
        (new Audit($this->pdo, $this->clock))->log('process.imported', $ctx->tenantId, $ctx->userId, 'process', null, $ip,
            ['created' => $created, 'skipped' => count($skipped)]);
        return ['created' => $created, 'skipped' => $skipped, 'stopped_by_limit' => $stopped];
    }

    /** CSV dos processos do escritório (sem arquivados), pronto para o Excel em português. */
    public function exportProcesses(TenantContext $ctx): string
    {
        $ctx->require('process.view');
        $stmt = $this->pdo->prepare(
            "SELECT p.cnj, p.client_label, p.tribunal, u.name AS responsible, p.status, p.last_success_at, p.last_error, p.created_at
               FROM processes p LEFT JOIN users u ON u.id = p.responsible_user_id
              WHERE p.tenant_id = ? AND p.status <> 'archived' ORDER BY p.id"
        );
        $stmt->execute([$ctx->tenantId]);
        $out = [['Processo', 'Cliente', 'Tribunal', 'Responsável', 'Situação', 'Última consulta com sucesso (UTC)', 'Último erro', 'Cadastrado em (UTC)']];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [$r['cnj'], $r['client_label'], $r['tribunal'], $r['responsible'], ['active' => 'Ativo', 'paused' => 'Pausado'][$r['status']] ?? $r['status'],
                $r['last_success_at'], $r['last_error'], $r['created_at']];
        }
        return self::toCsv($out);
    }

    /** CSV das publicações por OAB. */
    public function exportPublications(TenantContext $ctx, ?int $year = null): string
    {
        $ctx->require('oab.view');
        $sql = "SELECT p.published_at, p.cnj, p.tribunal, p.segment, p.uf, r.lawyer_name, r.oab_number, r.oab_uf, p.orgao, p.class_name, p.description, p.source_url
                  FROM oab_publications p JOIN oab_registrations r ON r.id = p.oab_id AND r.tenant_id = p.tenant_id
                 WHERE p.tenant_id = ?" . ($year !== null ? ' AND p.pub_year = ?' : '') . ' ORDER BY p.published_at DESC, p.id DESC LIMIT 20000';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($year !== null ? [$ctx->tenantId, $year] : [$ctx->tenantId]);
        $out = [['Publicada em', 'Processo', 'Tribunal', 'Justiça', 'UF', 'Advogado(a)', 'OAB', 'Órgão', 'Classe', 'Texto', 'Fonte oficial']];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [$r['published_at'], $r['cnj'], $r['tribunal'], ucfirst(Tribunals::segmentGroup((int) $r['segment'])), $r['uf'] ?? 'indeterminada',
                $r['lawyer_name'], $r['oab_number'] . '/' . $r['oab_uf'], $r['orgao'], $r['class_name'], $r['description'], $r['source_url']];
        }
        return self::toCsv($out);
    }

    /** @return list<list<string>> */
    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $csv = str_replace("\r\n", "\n", str_replace("\r", "\n", $csv));
        $firstLine = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') && str_contains($firstLine, ';') ? ';' : (str_contains($firstLine, "\t") ? "\t" : ',');
        $rows = [];
        foreach (explode("\n", $csv) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map(static fn ($c) => (string) $c, str_getcsv($line, $delimiter, '"', ''));
        }
        return $rows;
    }

    /** @param list<string> $row */
    private static function looksLikeHeader(array $row): bool
    {
        return Cnj::parse((string) ($row[0] ?? '')) === null && preg_match('/\d{7}/', (string) ($row[0] ?? '')) !== 1;
    }

    /**
     * @param list<list<mixed>> $rows
     * Planilhas executam fórmulas: célula que começa com = + - @ vira texto (apóstrofo na frente). BOM para o Excel ler acentos.
     */
    public static function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static function ($v): string {
                $v = (string) ($v ?? '');
                return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
            }, $row), ';', '"', '');
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }
}
