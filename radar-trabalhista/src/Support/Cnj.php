<?php
declare(strict_types=1);

namespace Radar\Support;

/**
 * Número único do processo (Resolução CNJ 65/2008): NNNNNNN-DD.AAAA.J.TR.OOOO.
 * Confere o formato E o dígito verificador (módulo 97).
 */
final class Cnj
{
    private const SEGMENTS = [
        1 => 'Supremo Tribunal Federal', 2 => 'Conselho Nacional de Justiça', 3 => 'Superior Tribunal de Justiça',
        4 => 'Justiça Federal', 5 => 'Justiça do Trabalho', 6 => 'Justiça Eleitoral',
        7 => 'Justiça Militar da União', 8 => 'Justiça Estadual', 9 => 'Justiça Militar Estadual',
    ];
    private const UF_BY_CODE = [
        1 => 'AC', 2 => 'AL', 3 => 'AP', 4 => 'AM', 5 => 'BA', 6 => 'CE', 7 => 'DF', 8 => 'ES', 9 => 'GO',
        10 => 'MA', 11 => 'MT', 12 => 'MS', 13 => 'MG', 14 => 'PA', 15 => 'PB', 16 => 'PR', 17 => 'PE',
        18 => 'PI', 19 => 'RJ', 20 => 'RN', 21 => 'RS', 22 => 'RO', 23 => 'RR', 24 => 'SC', 25 => 'SE',
        26 => 'SP', 27 => 'TO',
    ];
    /** Na Justiça Estadual os códigos de tribunal 1..27 não seguem a ordem alfabética das UFs. */
    private const TJ_BY_CODE = [
        1 => 'TJAC', 2 => 'TJAL', 3 => 'TJAP', 4 => 'TJAM', 5 => 'TJBA', 6 => 'TJCE', 7 => 'TJDFT', 8 => 'TJES',
        9 => 'TJGO', 10 => 'TJMA', 11 => 'TJMT', 12 => 'TJMS', 13 => 'TJMG', 14 => 'TJPA', 15 => 'TJPB',
        16 => 'TJPR', 17 => 'TJPE', 18 => 'TJPI', 19 => 'TJRJ', 20 => 'TJRN', 21 => 'TJRS', 22 => 'TJRO',
        23 => 'TJRR', 24 => 'TJSC', 25 => 'TJSE', 26 => 'TJSP', 27 => 'TJTO',
    ];

    private function __construct(
        public readonly string $digits,
        public readonly int $segment,
        public readonly int $tribunalCode,
        public readonly int $year,
    ) {
    }

    /** Devolve null se o texto não for um número CNJ válido (formato e dígito verificador). */
    public static function parse(string $input): ?self
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($digits) !== 20) {
            return null;
        }
        // com pontuação, ela precisa estar no lugar certo; só com dígitos também vale
        if (preg_match('/[^\d.\-\s]/', $input) === 1) {
            return null;
        }
        $seq = substr($digits, 0, 7);
        $dv = substr($digits, 7, 2);
        $rest = substr($digits, 9); // AAAA J TR OOOO
        if (self::checkDigit($seq, $rest) !== $dv) {
            return null;
        }
        $segment = (int) $rest[4];
        if ($segment < 1 || $segment > 9) {
            return null;
        }
        return new self($digits, $segment, (int) substr($rest, 5, 2), (int) substr($rest, 0, 4));
    }

    /** DD = 98 − ((NNNNNNN AAAA JTR OOOO 00) mod 97), em dois dígitos. */
    private static function checkDigit(string $seq, string $rest): string
    {
        $remainder = self::mod97($seq . $rest . '00');
        return str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);
    }

    /** mod 97 de um número grande, em blocos (sem depender de bcmath). */
    private static function mod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }
        return $remainder;
    }

    public function formatted(): string
    {
        $d = $this->digits;
        return sprintf('%s-%s.%s.%s.%s.%s', substr($d, 0, 7), substr($d, 7, 2), substr($d, 9, 4), $d[13], substr($d, 14, 2), substr($d, 16, 4));
    }

    public function segmentName(): string
    {
        return self::SEGMENTS[$this->segment] ?? 'Desconhecido';
    }

    /** Sigla do tribunal (TJMG, TRF6, TRT3, TST...). */
    public function tribunal(): string
    {
        $code = $this->tribunalCode;
        return match ($this->segment) {
            1 => 'STF',
            2 => 'CNJ',
            3 => 'STJ',
            4 => 'TRF' . $code,
            5 => $code === 0 ? 'TST' : 'TRT' . $code,
            6 => $code === 0 ? 'TSE' : 'TRE-' . (self::UF_BY_CODE[$code] ?? (string) $code),
            7 => 'STM',
            8 => self::TJ_BY_CODE[$code] ?? sprintf('TJ%02d', $code),
            9 => 'TJM-' . (self::UF_BY_CODE[$code] ?? (string) $code),
            default => 'SEG' . $this->segment . '-TR' . $code,
        };
    }

    /** UF do tribunal, quando ele é de um estado só (Estadual, Eleitoral, Militar estadual). */
    public function uf(): ?string
    {
        return in_array($this->segment, [6, 8, 9], true) ? (self::UF_BY_CODE[$this->tribunalCode] ?? null) : null;
    }

    public function isLabor(): bool
    {
        return $this->segment === 5;
    }
}
