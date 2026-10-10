<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use DateTimeImmutable;

/** Fonte que sabe listar o que foi publicado em nome de uma OAB. */
interface OabSource
{
    /**
     * @return list<OabPublication> publicações de `$since` até `$until` (inclusive)
     * @throws SourceUnavailable  a fonte não respondeu de forma definitiva, ou o volume passou do limite seguro
     * @throws InvalidResponse
     */
    public function fetchByOab(string $oabNumber, string $oabUf, DateTimeImmutable $since, DateTimeImmutable $until): array;
}
