<?php
declare(strict_types=1);

namespace Radar\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Relógio injetável: os testes avançam o tempo sem esperar. Tudo em UTC. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function sql(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
