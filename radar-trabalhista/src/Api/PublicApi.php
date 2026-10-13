<?php
declare(strict_types=1);

namespace Radar\Api;

use DateTimeImmutable;
use PDO;
use Radar\Http\Request;
use Radar\Http\Response;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\InvalidResponse;
use Radar\Monitoring\SourceUnavailable;
use Radar\Service\LookupService;
use Radar\Support\Clock;
use Radar\Support\Cnj;
use Throwable;

/**
 * API comercial (/v1). Autenticação por chave (Bearer ou X-API-Key), limite por minuto, cota mensal,
 * medição de uso e cache curto das consultas (protege as fontes públicas e o bolso do cliente).
 * Sem sessão, sem cookie, sem CORS: a chave é para servidor, nunca para navegador.
 */
final class PublicApi
{
    private const CACHE_SECONDS = 600;
    private const MAX_OAB_DAYS = 31;

    public function __construct(
        private PDO $pdo,
        private LookupService $lookup,
        private ?DjenAdapter $djen,
        private Clock $clock = new Clock(),
    ) {
    }

    public static function handles(string $path): bool
    {
        return $path === '/v1' || str_starts_with($path, '/v1/');
    }

    public function handle(Request $r): Response
    {
        $path = rtrim($r->path, '/');
        try {
            if ($r->method !== 'GET') {
                return $this->fail(405, 'metodo_nao_permitido', 'Use GET.', ['Allow' => 'GET']);
            }
            if ($path === '/v1/saude') {
                return $this->ok(['status' => 'ok', 'hora' => $this->clock->now()->format('c')]);
            }
            if ($path === '/v1/openapi.json') {
                return $this->ok(OpenApi::spec());
            }
            $auth = $this->authenticate($r);
            if ($auth instanceof Response) {
                return $auth;
            }
            $limits = $this->limits($auth, $path === '/v1/uso');   // consultar o próprio consumo nunca é barrado pela cota
            if ($limits instanceof Response) {
                return $limits;
            }
            [$response, $endpoint, $billable, $cached] = $this->route($r, $path, $auth, $limits);
            $this->meter($auth, $endpoint, $response->status, $billable, $cached);
            return $this->withLimitHeaders($response, $limits, $billable);
        } catch (\Radar\Exception\ValidationError $e) {
            $resp = $this->fail(422, 'parametro_invalido', $e->getMessage());
            if (isset($auth) && is_array($auth)) {
                $this->meter($auth, 'erro', 422, false, false);
            }
            return $resp;
        } catch (Throwable $e) {
            error_log('[radar-api] ' . $e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            return $this->fail(500, 'erro_interno', 'Erro interno. Tente de novo em instantes.');
        }
    }

    // ── autenticação, limite e cota ──────────────────────────────────────────

    /** @return array<string,mixed>|Response */
    private function authenticate(Request $r): array|Response
    {
        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $r->header('authorization'), $m) === 1) {
            $token = $m[1];
        } elseif ($r->header('x-api-key') !== '') {
            $token = trim($r->header('x-api-key'));
        }
        if ($token === '') {
            return $this->fail(401, 'chave_ausente', 'Envie a chave no cabeçalho Authorization: Bearer <chave> (ou X-API-Key).', ['WWW-Authenticate' => 'Bearer']);
        }
        if (!ApiKeys::looksValid($token)) {
            return $this->fail(401, 'chave_invalida', 'Chave inválida.', ['WWW-Authenticate' => 'Bearer']);
        }
        $stmt = $this->pdo->prepare(
            'SELECT k.id AS key_id, k.client_id, k.revoked_at, k.expires_at, c.status AS client_status, c.name AS client_name,
                    COALESCE(c.quota_override, p.monthly_quota) AS quota, p.rate_per_minute, p.name AS plan_name
               FROM api_keys k JOIN api_clients c ON c.id = k.client_id JOIN api_plans p ON p.id = c.plan_id WHERE k.key_hash = ?'
        );
        $stmt->execute([ApiKeys::hash($token)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return $this->fail(401, 'chave_invalida', 'Chave inválida.', ['WWW-Authenticate' => 'Bearer']);
        }
        if ($row['revoked_at'] !== null) {
            return $this->fail(403, 'chave_revogada', 'Esta chave foi revogada.');
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < Clock::sql($this->clock->now())) {
            return $this->fail(403, 'chave_expirada', 'Esta chave expirou.');
        }
        if ($row['client_status'] !== 'active') {
            return $this->fail(403, 'cliente_suspenso', 'Acesso suspenso. Fale com o suporte.');
        }
        $row['ip'] = $r->ip;
        return $row;
    }

    /**
     * Limite por minuto e cota do mês. Chamadas barradas aqui não gastam cota.
     * @param array<string,mixed> $a
     * @return array{rate:int,rate_left:int,quota:int,used:int,reset:int}|Response
     */
    private function limits(array $a, bool $skipQuota = false): array|Response
    {
        $now = $this->clock->now();
        $bucket = intdiv($now->getTimestamp(), 60);
        $this->pdo->prepare('INSERT INTO api_rate (key_id, bucket, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$a['key_id'], $bucket]);
        $hits = $this->pdo->prepare('SELECT hits FROM api_rate WHERE key_id = ? AND bucket = ?');
        $hits->execute([$a['key_id'], $bucket]);
        $n = (int) $hits->fetchColumn();
        $rate = (int) $a['rate_per_minute'];
        $retry = 60 - ($now->getTimestamp() % 60);
        if ($n > $rate) {
            $this->meter($a, 'limite', 429, false, false);
            return $this->fail(429, 'limite_por_minuto', "Limite de $rate consultas por minuto. Tente de novo em {$retry}s.", ['Retry-After' => (string) $retry,
                'X-RateLimit-Limit' => (string) $rate, 'X-RateLimit-Remaining' => '0']);
        }
        $used = $this->pdo->prepare('SELECT COALESCE(SUM(billable), 0) FROM api_usage_daily WHERE client_id = ? AND day >= ?');
        $used->execute([$a['client_id'], $now->format('Y-m-01')]);
        $usedMonth = (int) $used->fetchColumn();
        $quota = (int) $a['quota'];
        $reset = (new DateTimeImmutable($now->format('Y-m-01')))->modify('+1 month')->getTimestamp();
        if ($usedMonth >= $quota && !$skipQuota) {
            $this->meter($a, 'cota', 429, false, false);
            return $this->fail(429, 'cota_mensal_esgotada', "A cota de $quota consultas do mês acabou. Ela volta no dia 1º; para ampliar, fale com o suporte.", [
                'Retry-After' => (string) max(1, $reset - $now->getTimestamp()), 'X-Quota-Limit' => (string) $quota, 'X-Quota-Remaining' => '0']);
        }
        return ['rate' => $rate, 'rate_left' => max(0, $rate - $n), 'quota' => $quota, 'used' => $usedMonth, 'reset' => $reset];
    }

    /** @param array<string,mixed> $a */
    private function meter(array $a, string $endpoint, int $status, bool $billable, bool $cached): void
    {
        $this->pdo->prepare(
            'INSERT INTO api_usage_daily (key_id, client_id, day, endpoint, requests, billable, errors, cache_hits) VALUES (?, ?, ?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE requests = requests + 1, billable = billable + VALUES(billable), errors = errors + VALUES(errors), cache_hits = cache_hits + VALUES(cache_hits)'
        )->execute([$a['key_id'], $a['client_id'], $this->clock->now()->format('Y-m-d'), $endpoint, $billable ? 1 : 0, $status >= 400 ? 1 : 0, $cached ? 1 : 0]);
        $this->pdo->prepare('UPDATE api_keys SET last_used_at = ?, last_used_ip = ? WHERE id = ?')->execute([Clock::sql($this->clock->now()), substr((string) ($a['ip'] ?? ''), 0, 45), $a['key_id']]);
    }

    /** @param array{rate:int,rate_left:int,quota:int,used:int,reset:int} $l */
    private function withLimitHeaders(Response $resp, array $l, bool $billable): Response
    {
        $used = $l['used'] + ($billable ? 1 : 0);
        $resp->headers += ['X-RateLimit-Limit' => (string) $l['rate'], 'X-RateLimit-Remaining' => (string) $l['rate_left'],
            'X-Quota-Limit' => (string) $l['quota'], 'X-Quota-Remaining' => (string) max(0, $l['quota'] - $used)];
        return $resp;
    }

    // ── rotas ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $a
     * @param array{rate:int,rate_left:int,quota:int,used:int,reset:int} $limits
     * @return array{0:Response,1:string,2:bool,3:bool} resposta, nome do endpoint, conta na cota?, veio do cache?
     */
    private function route(Request $r, string $path, array $a, array $limits): array
    {
        if ($path === '/v1/uso') {
            return [$this->ok(['cliente' => $a['client_name'], 'plano' => $a['plan_name'], 'cota_mensal' => $limits['quota'], 'usadas_no_mes' => $limits['used'],
                'restantes' => max(0, $limits['quota'] - $limits['used']), 'limite_por_minuto' => $limits['rate'], 'cota_renova_em' => gmdate('c', $limits['reset'])]), 'uso', false, false];
        }
        if (preg_match('~^/v1/processos/([0-9.\-]{15,30})$~', $path, $m) === 1) {
            $cnj = Cnj::parse(urldecode($m[1])) ?? throw new \Radar\Exception\ValidationError('Número fora do padrão CNJ. Confira os dígitos (0000000-00.0000.0.00.0000).');
            return $this->process($cnj);
        }
        if (preg_match('~^/v1/oab/([A-Za-z]{2})/(\d{1,7})[A-Za-z]?/publicacoes$~', $path, $m) === 1) {
            return $this->oab(strtoupper($m[1]), $m[2], $r);
        }
        return [$this->fail(404, 'nao_encontrado', 'Endereço desconhecido. Veja /v1/openapi.json.'), 'desconhecido', false, false];
    }

    /** @return array{0:Response,1:string,2:bool,3:bool} */
    private function process(Cnj $cnj): array
    {
        $cached = $this->cacheGet('proc:' . $cnj->digits);
        $fromCache = $cached !== null;
        $data = $cached ?? $this->lookup->consolidate($cnj);
        $anyOk = false;
        foreach ($data['sources'] as $s) {
            $anyOk = $anyOk || $s['status'] === 'ok' || $s['status'] === 'not_found';
        }
        if (!$anyOk) {       // nenhuma fonte respondeu: não cobra e não guarda no cache
            return [$this->fail(502, 'fonte_indisponivel', 'Nenhuma fonte oficial respondeu agora. Tente de novo em instantes.', [], ['fontes' => $this->sources($data['sources'])]), 'processos', false, false];
        }
        if (!$fromCache && !array_filter($data['sources'], fn ($s) => $s['status'] === 'error')) {
            $this->cachePut('proc:' . $cnj->digits, $data);       // só guarda quando TODAS as fontes responderam
        }
        $case = $data['case'];
        $timeline = array_map(fn (array $t) => array_filter([
            'fonte' => $t['source'], 'data' => $t['date'], 'hora' => $t['time'] !== '' ? $t['time'] : null, 'tipo' => $t['kind'], 'descricao' => $t['text'], 'link' => $t['url'],
            'orgao' => ($t['orgao'] ?? '') !== '' ? $t['orgao'] : null, 'tipo_documento' => ($t['tipo_documento'] ?? '') !== '' ? $t['tipo_documento'] : null, 'meio' => ($t['meio'] ?? '') !== '' ? $t['meio'] : null,
            'partes' => $t['partes'] ?? null, 'advogados' => $t['advogados'] ?? null,
        ], fn ($v) => $v !== null && $v !== []), $data['timeline']);
        $body = [
            'numero' => $data['cnj'], 'tribunal' => $data['tribunal'], 'segmento' => $data['segment'], 'uf' => $data['uf'],
            'capa' => $case === null ? null : ['classe' => $case['classe'], 'assuntos' => $case['assuntos'], 'orgao_julgador' => $case['orgao'], 'grau' => $case['grau'],
                'data_ajuizamento' => $case['ajuizamento'], 'nivel_sigilo' => $case['sigilo'], 'atualizado_em' => $case['atualizado']],
            'partes' => $data['parties'], 'advogados' => $data['lawyers'], 'andamento' => $timeline, 'fontes' => $this->sources($data['sources']),
            'consultado_em' => $this->clock->now()->format('c'), 'cache' => $fromCache,
        ];
        return [$this->ok($body), 'processos', true, $fromCache];
    }

    /** @param list<array<string,mixed>> $sources */
    private function sources(array $sources): array
    {
        return array_map(fn ($s) => ['codigo' => $s['code'], 'status' => ['ok' => 'ok', 'error' => 'erro', 'not_found' => 'nao_encontrado', 'not_configured' => 'nao_configurada'][$s['status']] ?? $s['status'],
            'quantidade' => $s['count'], 'mensagem' => $s['message']], $sources);
    }

    /** @return array{0:Response,1:string,2:bool,3:bool} */
    private function oab(string $uf, string $number, Request $r): array
    {
        if (!in_array($uf, \Radar\Support\Tribunals::UFS, true)) {
            throw new \Radar\Exception\ValidationError('UF inválida.');
        }
        $today = new DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $end = $this->date($r->strQuery('fim')) ?? $today;
        $start = $this->date($r->strQuery('inicio')) ?? $end->modify('-6 days');
        if ($start > $end || $end > $today->modify('+1 day')) {
            throw new \Radar\Exception\ValidationError('Período inválido: use inicio e fim (AAAA-MM-DD), com inicio antes de fim e sem datas futuras.');
        }
        if ($start->diff($end)->days >= self::MAX_OAB_DAYS) {
            throw new \Radar\Exception\ValidationError('Período máximo de ' . self::MAX_OAB_DAYS . ' dias por consulta.');
        }
        if ($this->djen === null) {
            return [$this->fail(502, 'fonte_indisponivel', 'Fonte do Diário não configurada.'), 'oab', false, false];
        }
        $key = 'oab:' . $uf . ':' . $number . ':' . $start->format('Ymd') . ':' . $end->format('Ymd');
        $cached = $this->cacheGet($key);
        $fromCache = $cached !== null;
        if ($cached === null) {
            try {
                $items = array_slice($this->djen->fetchByOab($number, $uf, $start, $end), 0, 1000);
            } catch (SourceUnavailable | InvalidResponse $e) {
                return [$this->fail(502, 'fonte_indisponivel', $e->getMessage()), 'oab', false, false];
            }
            $cached = array_map(fn ($p) => array_filter([
                'id' => $p->externalId, 'numero' => Cnj::parse($p->cnjDigits)?->formatted() ?? $p->cnjDigits, 'tribunal' => Cnj::parse($p->cnjDigits)?->tribunal() ?? $p->tribunal,
                'data' => $p->publishedAt, 'tipo' => $p->kind, 'tipo_documento' => $p->docType, 'orgao' => $p->orgao, 'classe' => $p->className, 'texto' => $p->description, 'link' => $p->sourceUrl,
            ], fn ($v) => $v !== null && $v !== ''), $items);
            $this->cachePut($key, $cached);
        }
        return [$this->ok(['oab' => $number, 'uf' => $uf, 'inicio' => $start->format('Y-m-d'), 'fim' => $end->format('Y-m-d'), 'total' => count($cached), 'publicacoes' => $cached,
            'consultado_em' => $this->clock->now()->format('c'), 'cache' => $fromCache]), 'oab', true, $fromCache];
    }

    private function date(string $s): ?DateTimeImmutable
    {
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new \Radar\Exception\ValidationError('Data inválida: use AAAA-MM-DD.');
        }
        return $d;
    }

    // ── cache ────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|list<mixed>|null */
    private function cacheGet(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM api_cache WHERE cache_key = ? AND expires_at > ?');
        $stmt->execute([$key, Clock::sql($this->clock->now())]);
        $payload = $stmt->fetchColumn();
        $data = $payload === false ? null : json_decode((string) $payload, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<mixed> $data */
    private function cachePut(string $key, array $data): void
    {
        $this->pdo->prepare('INSERT INTO api_cache (cache_key, payload, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)')
            ->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]', Clock::sql($this->clock->now()->modify('+' . self::CACHE_SECONDS . ' seconds'))]);
    }

    // ── respostas ────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private function ok(array $data): Response
    {
        return new Response(200, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $extra
     */
    private function fail(int $status, string $code, string $message, array $headers = [], array $extra = []): Response
    {
        return new Response($status, json_encode(['erro' => ['codigo' => $code, 'mensagem' => $message] + $extra], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $headers + ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
