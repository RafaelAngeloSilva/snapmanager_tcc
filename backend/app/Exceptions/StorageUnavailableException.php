<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O armazenamento de arquivos (disco local ou S3) não respondeu como
 * esperado: falha de rede, credencial inválida, bucket inexistente,
 * permissão negada, disco cheio...
 *
 * A mensagem é SEMPRE a amigável — os detalhes técnicos (operação, object
 * key e erro sanitizado) vão só para o log, em FileStorage.
 */
class StorageUnavailableException extends RuntimeException
{
    public const USER_MESSAGE = 'Não foi possível carregar o arquivo. Tente novamente.';

    public function __construct(public readonly string $operation = 'read')
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
