<?php
declare(strict_types=1);

namespace Radar\Exception;

use RuntimeException;

/** Erro esperado, com mensagem pronta para mostrar ao usuário. */
final class AuthError extends RuntimeException
{
    public function __construct(string $message = 'E-mail ou senha incorretos.')
    {
        parent::__construct($message);
    }
}
