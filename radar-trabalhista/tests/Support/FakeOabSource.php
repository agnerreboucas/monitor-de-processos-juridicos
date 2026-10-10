<?php
declare(strict_types=1);

namespace Radar\Tests\Support;

use DateTimeImmutable;
use Radar\Monitoring\OabPublication;
use Radar\Monitoring\OabSource;
use Throwable;

/** Fonte por OAB falsa: devolve o que o teste mandar e anota as chamadas. */
final class FakeOabSource implements OabSource
{
    /** @var list<array{oab:string,uf:string,since:string,until:string}> */
    public array $calls = [];
    /** @var list<list<OabPublication>|Throwable> */
    private array $script = [];

    /** @param list<OabPublication>|Throwable $next */
    public function then(array|Throwable $next): self
    {
        $this->script[] = $next;
        return $this;
    }

    public function fetchByOab(string $oabNumber, string $oabUf, DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $this->calls[] = ['oab' => $oabNumber, 'uf' => $oabUf, 'since' => $since->format('Y-m-d'), 'until' => $until->format('Y-m-d')];
        $next = array_shift($this->script) ?? [];
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }

    /** Número CNJ válido (dígito verificador calculado) para a Justiça e o tribunal pedidos. */
    public static function cnj(int $seq, int $year, int $segment, string $tr, string $origin = '0001'): string
    {
        $base = sprintf('%07d%04d%d%s%s', $seq, $year, $segment, $tr, $origin);
        $rest = 0;
        foreach (str_split($base . '00') as $digit) {
            $rest = ($rest * 10 + (int) $digit) % 97;
        }
        $d = $base;
        $dv = sprintf('%02d', 98 - $rest);
        return sprintf('%s-%s.%s.%s.%s.%s', substr($d, 0, 7), $dv, substr($d, 7, 4), substr($d, 11, 1), substr($d, 12, 2), substr($d, 14, 4));
    }

    public static function pub(string $id, string $cnj, string $date = '2026-09-01', string $text = 'Intimação de teste'): OabPublication
    {
        return new OabPublication('djen-' . $id, preg_replace('/\D/', '', $cnj), $date, 'Intimação', 'Despacho', 'Vara Teste', 'Classe', $text, '<p>' . $text . '</p>', 'https://tribunal.exemplo/' . $id, '');
    }
}
