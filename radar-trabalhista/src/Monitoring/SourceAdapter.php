<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use DateTimeImmutable;
use Radar\Support\Cnj;

/**
 * Contrato de uma fonte de dados processuais. O restante do sistema só conhece esta interface:
 * trocar ou acrescentar uma fonte não exige mexer em cadastro, painel, planos ou multiempresa.
 */
interface SourceAdapter
{
    /** Código da fonte em `integration_sources.code` (ex.: "djen"). */
    public function code(): string;

    /**
     * Busca as movimentações do processo (a partir de `$since`, ou todo o histórico se null).
     * Lista vazia significa "a fonte respondeu e não há movimentação".
     *
     * @return list<Movement>
     * @throws SourceUnavailable  a fonte não respondeu de forma definitiva (vale tentar de novo)
     * @throws InvalidResponse    a fonte respondeu, mas o conteúdo não faz sentido
     */
    public function fetch(Cnj $cnj, ?DateTimeImmutable $since): array;
}
