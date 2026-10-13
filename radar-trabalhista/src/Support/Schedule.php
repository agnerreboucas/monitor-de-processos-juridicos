<?php
declare(strict_types=1);

namespace Radar\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Horário fixo das buscas automáticas (sempre no horário de Brasília). */
final class Schedule
{
    public const TZ = 'America/Sao_Paulo';

    /** "5:30", "05:30" ou "05:30:00" viram "05:30:00"; vazio vira null; formato inválido vira false. */
    public static function normalize(?string $time): string|false|null
    {
        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $m) !== 1) {
            return false;
        }
        return sprintf('%02d:%s:00', (int) $m[1], $m[2]);
    }

    /** Próxima vez (UTC) em que o relógio de Brasília marca `$time`, estritamente depois de `$now`. */
    public static function nextRun(DateTimeImmutable $now, string $time): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $local = $now->setTimezone(new DateTimeZone(self::TZ));
        $candidate = $local->setTime($h, $m, 0);
        if ($candidate <= $local) {
            $candidate = $candidate->modify('+1 day');
        }
        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    /** Data local (Brasília) no formato aaaa-mm-dd. */
    public static function localDay(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }
}
