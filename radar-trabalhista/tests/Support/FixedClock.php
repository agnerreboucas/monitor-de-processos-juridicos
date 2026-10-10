<?php
declare(strict_types=1);

namespace Radar\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Radar\Support\Clock;

/** Relógio que só anda quando o teste manda. */
final class FixedClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-10-10 12:00:00')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new DateInterval($interval));
    }
}
