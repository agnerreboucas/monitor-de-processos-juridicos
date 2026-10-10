<?php
declare(strict_types=1);

namespace Radar\Exception;

use RuntimeException;

/** Erro esperado, com mensagem pronta para mostrar ao usuário. */
final class Forbidden extends RuntimeException
{
    public function __construct(string $message = 'Sem permissão para esta operação.')
    {
        parent::__construct($message);
    }
}
