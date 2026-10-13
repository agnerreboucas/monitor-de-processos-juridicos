<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Radar\Support\Cnj;

/**
 * DJEN / Comunica (CNJ): publicações do Diário de Justiça Eletrônico Nacional.
 * https://comunicaapi.pje.jus.br/api/v1/comunicacao
 *
 * Portado do robô em Python (consulta_processos/fontes/comunica.py). Atenção: o gov.br só
 * responde a IP do Brasil (de fora, HTTP 403) — a hospedagem precisa estar no Brasil ou usar proxy.
 */
final class DjenAdapter implements SourceAdapter, OabSource
{
    public const PAGE_SIZE = 100;
    private const MAX_PAGES = 20;
    /** Cada janela de busca por OAB cobre até 31 dias e no máximo 30 páginas (3000 publicações). */
    private const OAB_WINDOW_DAYS = 31;
    private const OAB_MAX_PAGES = 30;

    public function __construct(
        private HttpClient $http,
        private string $url = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao',
        private int $timeoutSeconds = 15,
        private int $pageDelayMicros = 0,   // respeito ao serviço público entre páginas
    ) {
    }

    public function code(): string
    {
        return 'djen';
    }

    public function fetch(Cnj $cnj, ?DateTimeImmutable $since): array
    {
        $today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $movements = [];
        $seen = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->page($cnj, $since, $today, $page);
            $items = $data['items'] ?? [];
            if (!is_array($items)) {
                throw new InvalidResponse('A fonte devolveu um formato inesperado.');
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    $movements[] = $this->normalize($item);
                }
            }
            $total = (int) ($data['count'] ?? 0);
            $seen += count($items);     // conta o que veio, não o que pedimos: o servidor pode limitar o tamanho da página
            if ($items === [] || $seen >= $total) {
                break;
            }
        }
        return $movements;
    }

    public function fetchByOab(string $oabNumber, string $oabUf, DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $digits = preg_replace('/\D/', '', $oabNumber) ?? '';
        if ($digits === '' || preg_match('/^[A-Za-z]{2}$/', $oabUf) !== 1) {
            throw new InvalidResponse('OAB inválida.');
        }
        $out = [];
        $from = $since;
        while ($from <= $until) {
            $to = min($until, $from->modify('+' . (self::OAB_WINDOW_DAYS - 1) . ' days'));
            $total = 0;
            $seen = 0;
            for ($page = 1; $page <= self::OAB_MAX_PAGES; $page++) {
                if ($page > 1 && $this->pageDelayMicros > 0) {
                    usleep($this->pageDelayMicros);
                }
                $data = $this->request([
                    'numeroOab' => $digits, 'ufOab' => strtoupper($oabUf),
                    'dataDisponibilizacaoInicio' => $from->format('Y-m-d'), 'dataDisponibilizacaoFim' => $to->format('Y-m-d'),
                    'itensPorPagina' => self::PAGE_SIZE, 'pagina' => $page,
                ]);
                $items = $data['items'] ?? [];
                if (!is_array($items)) {
                    throw new InvalidResponse('A fonte devolveu um formato inesperado.');
                }
                $total = (int) ($data['count'] ?? 0);
                $seen += count($items);
                foreach ($items as $item) {
                    if (is_array($item) && ($publication = $this->oabPublication($item)) !== null) {
                        $out[] = $publication;
                    }
                }
                if ($items === [] || $seen >= $total) {
                    $total = 0;
                    break;
                }
            }
            if ($total > 0) {      // saiu do laço por limite de páginas: não pode fingir que pegou tudo
                throw new SourceUnavailable('Volume de publicações acima do limite seguro por janela; reduza o período da consulta.');
            }
            $from = $to->modify('+1 day');
        }
        return $out;
    }

    /** @param array<string,mixed> $item */
    private function oabPublication(array $item): ?OabPublication
    {
        $id = (string) ($item['id'] ?? '');
        $cnj = Cnj::parse((string) ($item['numero_processo'] ?? $item['numeroprocessocommascara'] ?? ''));
        if ($id === '' || $cnj === null) {
            return null;           // sem identificador ou com número que não é CNJ válido: não dá para guardar nem deduplicar
        }
        $raw = (string) ($item['texto'] ?? '');
        $link = (string) ($item['link'] ?? '');
        return new OabPublication(
            'djen-' . $id, $cnj->digits, self::date($item['data_disponibilizacao'] ?? $item['datadisponibilizacao'] ?? null),
            mb_substr(trim((string) ($item['tipoComunicacao'] ?? '')), 0, 80), mb_substr(trim((string) ($item['tipoDocumento'] ?? '')), 0, 120),
            mb_substr(trim((string) ($item['nomeOrgao'] ?? '')), 0, 255), mb_substr(trim((string) ($item['nomeClasse'] ?? '')), 0, 255),
            self::plainText($raw), $raw !== '' ? $raw : null, str_starts_with($link, 'https://') ? mb_substr($link, 0, 500) : null,
            strtoupper(trim((string) ($item['siglaTribunal'] ?? ''))),
        );
    }

    /** @return array<string,mixed> */
    private function page(Cnj $cnj, ?DateTimeImmutable $since, DateTimeImmutable $today, int $page): array
    {
        return $this->request([
            'numeroProcesso' => $cnj->digits,
            'dataDisponibilizacaoInicio' => ($since ?? new DateTimeImmutable('2015-01-01'))->format('Y-m-d'),
            'dataDisponibilizacaoFim' => $today->format('Y-m-d'),
            'itensPorPagina' => self::PAGE_SIZE,
            'pagina' => $page,
        ]);
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function request(array $query): array
    {
        $response = $this->http->get($this->url, $query, [
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'pt-BR,pt;q=0.9',
            'Origin' => 'https://comunica.pje.jus.br',
            'Referer' => 'https://comunica.pje.jus.br/',
            'User-Agent' => 'Mozilla/5.0 (compatible; RadarTrabalhista/1.0)',
        ], $this->timeoutSeconds);

        if ($response->status === 0) {
            throw new SourceUnavailable('Sem resposta do DJEN (' . ($response->error ?? 'falha de rede') . ').');
        }
        if ($response->status === 403) {
            throw new SourceUnavailable('Acesso recusado pelo DJEN (HTTP 403). Verifique se o servidor está no Brasil ou use proxy.');
        }
        if ($response->status === 429) {
            throw new SourceUnavailable('DJEN limitou as consultas (HTTP 429).');
        }
        if ($response->status >= 500) {
            throw new SourceUnavailable('DJEN indisponível (HTTP ' . $response->status . ').');
        }
        if ($response->status === 400 || $response->status === 404) {
            return ['items' => [], 'count' => 0];          // o DJEN responde assim quando não há nada
        }
        if ($response->status !== 200) {
            throw new SourceUnavailable('Resposta inesperada do DJEN (HTTP ' . $response->status . ').');
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidResponse('O DJEN devolveu algo que não é JSON.');
        }
        if (!is_array($data)) {
            throw new InvalidResponse('O DJEN devolveu um formato inesperado.');
        }
        return $data;
    }

    /** @param array<string,mixed> $item */
    private function normalize(array $item): Movement
    {
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            throw new InvalidResponse('Publicação sem identificador na resposta do DJEN.');
        }
        $raw = (string) ($item['texto'] ?? '');
        $link = (string) ($item['link'] ?? '');
        return new Movement(
            externalId: 'djen-' . $id,
            occurredAt: self::date($item['data_disponibilizacao'] ?? $item['datadisponibilizacao'] ?? null),
            kind: mb_substr(trim((string) ($item['tipoComunicacao'] ?? '')), 0, 80),
            description: self::plainText($raw),
            descriptionRaw: $raw !== '' ? $raw : null,
            sourceUrl: str_starts_with($link, 'https://') ? mb_substr($link, 0, 500) : null,
            metadata: array_filter([
                'orgao' => $item['nomeOrgao'] ?? null,
                'tipo_documento' => $item['tipoDocumento'] ?? null,
                'classe' => $item['nomeClasse'] ?? null,
                'tribunal' => $item['siglaTribunal'] ?? null,
                'hash' => $item['hash'] ?? null,
                'meio' => $item['meiocompleto'] ?? null,
                'partes' => self::parties($item['destinatarios'] ?? null),
                'advogados' => self::lawyers($item['destinatarioadvogados'] ?? null),
            ], static fn ($v) => $v !== null && $v !== '' && $v !== []),
        );
    }

    /** @return list<array{nome:string,polo:string}> partes citadas na publicação (A = ativo, P = passivo), no máximo 30 */
    private static function parties(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $p) {
            $name = is_array($p) ? trim((string) ($p['nome'] ?? '')) : '';
            if ($name !== '' && count($out) < 30) {
                $out[] = ['nome' => mb_substr($name, 0, 150), 'polo' => in_array($p['polo'] ?? '', ['A', 'P'], true) ? $p['polo'] : ''];
            }
        }
        return $out;
    }

    /** @return list<array{nome:string,oab:string,uf:string}> advogados citados (o número da OAB vem como foi publicado, ex.: 128733A) */
    private static function lawyers(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $row) {
            $l = is_array($row) && is_array($row['advogado'] ?? null) ? $row['advogado'] : null;
            $name = $l !== null ? trim((string) ($l['nome'] ?? '')) : '';
            if ($name !== '' && count($out) < 30) {
                $out[] = ['nome' => mb_substr($name, 0, 150), 'oab' => mb_substr(trim((string) ($l['numero_oab'] ?? '')), 0, 20), 'uf' => strtoupper(mb_substr(trim((string) ($l['uf_oab'] ?? '')), 0, 2))];
            }
        }
        return $out;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})~', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return null;
    }

    /** Tira tags, scripts e estilos e normaliza espaços. O original fica guardado à parte. */
    public static function plainText(string $html): string
    {
        $html = preg_replace(['~<style\b[^>]*>.*?</style>~is', '~<script\b[^>]*>.*?</script>~is'], ' ', $html) ?? $html;
        $html = preg_replace('~<\s*(br|/p|/div|/tr|/section|/h\d)\b[^>]*>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        return trim($text);
    }
}
