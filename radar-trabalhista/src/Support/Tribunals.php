<?php
declare(strict_types=1);

namespace Radar\Support;

/**
 * UF(s) cobertas por cada tribunal. A Seccional da OAB é por estado; por isso só dá para
 * dizer "este processo é da UF X" quando o tribunal cobre UMA UF. Justiça Estadual, Eleitoral e
 * Militar estadual sempre cobrem uma. Trabalho e Federal, só alguns. O resto é "indeterminada".
 */
final class Tribunals
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE',
        'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SE', 'SP', 'TO'];

    /** Tribunais regionais que cobrem mais de uma UF (TRT) ou várias (TRF). */
    private const REGIONAL = [
        'TRT1' => ['RJ'], 'TRT2' => ['SP'], 'TRT3' => ['MG'], 'TRT4' => ['RS'], 'TRT5' => ['BA'], 'TRT6' => ['PE'],
        'TRT7' => ['CE'], 'TRT8' => ['PA', 'AP'], 'TRT9' => ['PR'], 'TRT10' => ['DF', 'TO'], 'TRT11' => ['AM', 'RR'],
        'TRT12' => ['SC'], 'TRT13' => ['PB'], 'TRT14' => ['RO', 'AC'], 'TRT15' => ['SP'], 'TRT16' => ['MA'],
        'TRT17' => ['ES'], 'TRT18' => ['GO'], 'TRT19' => ['AL'], 'TRT20' => ['SE'], 'TRT21' => ['RN'], 'TRT22' => ['PI'],
        'TRT23' => ['MT'], 'TRT24' => ['MS'],
        'TRF1' => ['AC', 'AM', 'AP', 'BA', 'DF', 'GO', 'MA', 'MG', 'MT', 'PA', 'PI', 'RO', 'RR', 'TO'],
        'TRF2' => ['RJ', 'ES'], 'TRF3' => ['SP', 'MS'], 'TRF4' => ['RS', 'SC', 'PR'],
        'TRF5' => ['AL', 'CE', 'PB', 'PE', 'RN', 'SE'], 'TRF6' => ['MG'],
    ];

    /** @return list<string> as UFs possíveis do tribunal (vazio se não se aplica, ex.: STJ, TST) */
    public static function possibleUfs(string $tribunal): array
    {
        $tribunal = strtoupper($tribunal);
        if (isset(self::REGIONAL[$tribunal])) {
            return self::REGIONAL[$tribunal];
        }
        if (preg_match('/^TJ([A-Z]{2})$/', $tribunal, $m) === 1 && in_array($m[1], self::UFS, true)) {
            return [$m[1]];
        }
        if ($tribunal === 'TJDFT') {
            return ['DF'];
        }
        if (preg_match('/^(?:TRE|TJM)-([A-Z]{2})$/', $tribunal, $m) === 1 && in_array($m[1], self::UFS, true)) {
            return [$m[1]];
        }
        return [];
    }

    /** A UF do processo quando o tribunal cobre uma só; null quando é indeterminada. */
    public static function uf(string $tribunal): ?string
    {
        $ufs = self::possibleUfs($tribunal);
        return count($ufs) === 1 ? $ufs[0] : null;
    }

    /** estadual | federal | trabalho | outros — as colunas do painel por UF. */
    public static function segmentGroup(int $segment): string
    {
        return match ($segment) {
            8 => 'estadual',
            4 => 'federal',
            5 => 'trabalho',
            default => 'outros',
        };
    }
}
