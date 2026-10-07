<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O registro aponta para um arquivo que não existe no armazenamento.
 * Vira HTTP 404 (ver bootstrap/app.php) — a object key nunca é exposta.
 */
class StoredFileNotFoundException extends RuntimeException
{
    public const USER_MESSAGE = 'Arquivo não encontrado.';

    public function __construct()
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
