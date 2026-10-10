<?php
declare(strict_types=1);

namespace Radar\Monitoring;

use RuntimeException;

/** Fonte fora do ar, lenta, bloqueando o acesso ou limitando as chamadas. NÃO é "sem novidade". */
final class SourceUnavailable extends RuntimeException
{
}
