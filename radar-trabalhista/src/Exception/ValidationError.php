<?php
declare(strict_types=1);

namespace Radar\Exception;

use RuntimeException;

/** Erro esperado, com mensagem pronta para mostrar ao usuário. */
final class ValidationError extends RuntimeException
{
    public function __construct(string $message = 'Dados inválidos.')
    {
        parent::__construct($message);
    }
}
