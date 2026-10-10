<?php
declare(strict_types=1);

namespace Radar\Monitoring;

/** Uma publicação do diário em nome de um advogado, já normalizada. */
final class OabPublication
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $cnjDigits,        // 20 dígitos, já com dígito verificador conferido
        public readonly ?string $publishedAt,     // aaaa-mm-dd
        public readonly string $kind,
        public readonly string $docType,
        public readonly string $orgao,
        public readonly string $className,
        public readonly string $description,      // texto limpo
        public readonly ?string $descriptionRaw,  // original, sem alteração
        public readonly ?string $sourceUrl,
        public readonly string $tribunal,         // sigla informada pela fonte (ex.: TJMG), vazio se não informou
    ) {
    }
}
