<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ação sobre um documento que o estado atual não permite (ex.: enviar algo
 * que já foi enviado, notificar um rascunho). Vira HTTP 409 (ou o status
 * informado) com a mensagem amigável — nunca um 500.
 */
class DocumentActionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
