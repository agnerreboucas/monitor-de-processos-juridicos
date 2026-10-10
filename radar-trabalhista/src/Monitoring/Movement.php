<?php
declare(strict_types=1);

namespace Radar\Monitoring;

/** Uma movimentação já normalizada, independente da fonte de onde veio. */
final class Movement
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $externalId,      // estável na origem; é a chave da deduplicação
        public readonly ?string $occurredAt,     // aaaa-mm-dd, quando a fonte informa
        public readonly string $kind,
        public readonly string $description,     // texto limpo para leitura
        public readonly ?string $descriptionRaw, // original recebido, sem alteração
        public readonly ?string $sourceUrl,
        public readonly array $metadata = [],
    ) {
    }
}
