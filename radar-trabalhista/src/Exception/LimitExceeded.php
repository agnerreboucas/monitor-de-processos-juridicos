<?php
declare(strict_types=1);

namespace Radar\Exception;

use RuntimeException;

/** Erro esperado, com mensagem pronta para mostrar ao usuário. */
final class LimitExceeded extends RuntimeException
{
    public function __construct(string $message = 'Limite do plano atingido.')
    {
        parent::__construct($message);
    }
}
