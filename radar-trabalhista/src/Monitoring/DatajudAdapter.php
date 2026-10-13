<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Radar\Support\Cnj;

/**
 * Datajud (API pública do CNJ): andamento COMPLETO do processo (conclusão, remessa, juntada, baixa…),
 * inclusive o que não sai no diário, e os dados da capa (classe, assunto, órgão). Não traz o texto das
 * publicações (isso é o DJEN) e demora alguns dias para indexar o que acabou de acontecer.
 * A chave é pública (divulgada pelo CNJ) mas fica no .env, não no código.
 */
final class DatajudAdapter implements SourceAdapter
{
    public const BASE_URL = 'https://api-publica.datajud.cnj.jus.br';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $baseUrl = self::BASE_URL,
        private int $timeoutSeconds = 20,
    ) {
    }

    public function code(): string
    {
        return 'datajud';
    }

    /** O Datajud devolve o processo inteiro de uma vez; `$since` é ignorado (a deduplicação é do banco). */
    public function fetch(Cnj $cnj, ?DateTimeImmutable $since): array
    {
        return $this->lookup($cnj)['movements'] ?? [];
    }

    /** Nome do índice do tribunal no Datajud; null quando o Datajud não cobre (STF, CNJ). */
    public static function index(Cnj $cnj): ?string
    {
        $t = strtolower($cnj->tribunal());
        if (preg_match('/^(stf|cnj)$|^seg\d/', $t) === 1) {
            return null;
        }
        return 'api_publica_' . (str_starts_with($t, 'tjm-') ? str_replace('-', '', $t) : $t);   // tre-mg mantém o hífen; tjm-sp não
    }

    /**
     * @return array{tribunal:string,grau:string,classe:string,assuntos:list<string>,orgao:string,ajuizamento:?string,sigilo:int,atualizado:?string,instancias:int,movements:list<Movement>}|null
     *         null = o processo não está na base do Datajud
     * @throws SourceUnavailable|InvalidResponse
     */
    public function lookup(Cnj $cnj): ?array
    {
        $index = self::index($cnj);
        if ($index === null) {
            throw new SourceUnavailable('O Datajud não tem processos desse tribunal (' . $cnj->tribunal() . ').');
        }
        if (trim($this->apiKey) === '') {
            throw new SourceUnavailable('Falta a chave do Datajud (DATAJUD_API_KEY no arquivo .env).');
        }
        $res = $this->http->postJson($this->baseUrl . '/' . $index . '/_search',
            ['size' => 10, 'query' => ['match' => ['numeroProcesso' => $cnj->digits]]],
            ['Authorization' => 'APIKey ' . trim($this->apiKey), 'Accept' => 'application/json'], $this->timeoutSeconds);

        if ($res->status === 0) {
            throw new SourceUnavailable('Não foi possível falar com o Datajud (rede ou tempo esgotado).');
        }
        if (in_array($res->status, [401, 403], true)) {
            throw new SourceUnavailable('O Datajud recusou a chave de API (HTTP ' . $res->status . '). Confira DATAJUD_API_KEY.');
        }
        if ($res->status === 429 || $res->status >= 500) {
            throw new SourceUnavailable('O Datajud está sobrecarregado ou fora do ar (HTTP ' . $res->status . '). Tente mais tarde.');
        }
        if ($res->status === 404) {
            throw new SourceUnavailable('O Datajud não tem o índice do tribunal ' . $cnj->tribunal() . '.');
        }
        if ($res->status !== 200) {
            throw new InvalidResponse('O Datajud respondeu HTTP ' . $res->status . '.');
        }
        $data = json_decode($res->body, true);
        if (!is_array($data) || !is_array($data['hits']['hits'] ?? null)) {
            throw new InvalidResponse('O Datajud devolveu um formato inesperado.');
        }
        $hits = array_values(array_filter($data['hits']['hits'], fn ($h) => is_array($h) && is_array($h['_source'] ?? null)));
        if ($hits === []) {
            return null;
        }
        // mesmo número pode existir em mais de um grau (1º grau, recurso): junta o andamento de todos
        $movements = [];
        $used = [];
        foreach ($hits as $hit) {
            foreach ($this->movements($hit['_source'], $used) as $m) {
                $movements[] = $m;
            }
        }
        $main = $hits[0]['_source'];
        $assuntos = [];
        foreach ((array) ($main['assuntos'] ?? $main['assunto'] ?? []) as $a) {
            if (is_array($a) && trim((string) ($a['nome'] ?? '')) !== '') {
                $assuntos[] = trim((string) $a['nome']);
            }
        }
        return [
            'tribunal' => (string) ($main['tribunal'] ?? $cnj->tribunal()),
            'grau' => (string) ($main['grau'] ?? ''),
            'classe' => trim((string) ($main['classe']['nome'] ?? '')),
            'assuntos' => $assuntos,
            'orgao' => trim((string) ($main['orgaoJulgador']['nome'] ?? '')),
            'ajuizamento' => self::dateOf($main['dataAjuizamento'] ?? null),
            'sigilo' => (int) ($main['nivelSigilo'] ?? 0),
            'atualizado' => self::dateOf($main['dataHoraUltimaAtualizacao'] ?? null),
            'instancias' => count($hits),
            'movements' => $movements,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,int> $used
     * @return list<Movement>
     */
    private function movements(array $source, array &$used): array
    {
        $out = [];
        $grau = (string) ($source['grau'] ?? '');
        foreach ((array) ($source['movimentos'] ?? []) as $mov) {
            if (!is_array($mov)) {
                continue;
            }
            $name = trim((string) ($mov['nome'] ?? $mov['descricao'] ?? ''));
            $when = self::parseDateTime($mov['dataHora'] ?? null);
            if ($name === '' || $when === null) {
                continue;
            }
            $parts = [];
            foreach ((array) ($mov['complementosTabelados'] ?? []) as $c) {
                if (is_array($c)) {
                    $key = trim(str_replace('_', ' ', (string) ($c['nome'] ?? '')));
                    $value = trim((string) ($c['descricao'] ?? ''));
                    // "tipo de conclusao: para sentença" lê melhor só como "para sentença"
                    $label = $value !== '' && (str_starts_with($key, 'tipo de') || $key === '') ? $value : trim($key . ': ' . $value, ': ');
                    if ($label !== '') {
                        $parts[] = $label;
                    }
                }
            }
            $description = $name . ($parts !== [] ? ' — ' . implode('; ', $parts) : '');
            $id = 'datajud-' . (string) ($mov['codigo'] ?? '0') . '-' . $when->format('YmdHis') . '-' . substr(md5($grau . '|' . mb_substr($description, 0, 200)), 0, 8);
            $used[$id] = ($used[$id] ?? 0) + 1;   // dois atos idênticos no mesmo segundo não podem se fundir
            if ($used[$id] > 1) {
                $id .= '-' . $used[$id];
            }
            $out[] = new Movement($id, $when->format('Y-m-d'), mb_substr($name, 0, 80), $description, null, null, array_filter([
                'codigo' => $mov['codigo'] ?? null, 'hora' => $when->format('H:i'), 'grau' => $grau !== '' ? $grau : null,
                'orgao' => trim((string) ($mov['orgaoJulgador']['nomeOrgao'] ?? '')) ?: null,
            ], fn ($v) => $v !== null));
        }
        return $out;
    }

    /**
     * Aceita ISO 8601 e o formato compacto aaaammddhhmmss. O horário é lido COMO ESCRITO, sem conversão de fuso:
     * os tribunais gravam a hora local e muitos a marcam com "Z", então converter deslocaria a data do ato.
     */
    private static function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        if (preg_match('/^\d{14}$/', $value) === 1) {
            return DateTimeImmutable::createFromFormat('YmdHis', $value, $utc) ?: null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}(?::\d{2})?))?/', $value, $m) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1] . ' ' . (isset($m[2]) ? (strlen($m[2]) === 5 ? $m[2] . ':00' : $m[2]) : '00:00:00'), $utc);
        return $d !== false && $d->format('Y-m-d') === $m[1] ? $d : null;
    }

    private static function dateOf(mixed $value): ?string
    {
        return self::parseDateTime($value)?->format('Y-m-d');
    }
}
