<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha ao converter um DOCX em PDF. A mensagem é sempre amigável e segura
 * para mostrar ao usuário: nunca contém caminho do servidor, comando
 * executado ou saída do LibreOffice (esses detalhes vão só para o log).
 */
class DocumentConversionException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const TIMEOUT = 'timeout';

    public const FAILED = 'failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'O conversor de Word para PDF não está disponível no servidor. Envie o contrato em PDF ou peça ao administrador para configurar o LibreOffice.');
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, 'A conversão do Word para PDF demorou demais e foi interrompida. Tente novamente ou envie o contrato em PDF.');
    }

    public static function failed(): self
    {
        return new self(self::FAILED, 'Não foi possível converter o documento Word para PDF. Verifique se o arquivo abre normalmente no Word ou envie o contrato em PDF.');
    }
}
