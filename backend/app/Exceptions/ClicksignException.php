<?php

namespace App\Exceptions;

use App\Enums\ClicksignErrorReason;
use RuntimeException;
use Throwable;

/**
 * Única exceção que sai do ClicksignService.
 *
 * Regra de segurança: a mensagem é montada só com o motivo (enum), o status
 * HTTP e, quando existir, o `detail` devolvido pela própria Clicksign — já
 * sanitizado pelo service. Nunca carrega headers, URL com credencial ou o
 * corpo bruto da requisição, então pode ir para log sem vazar o token.
 */
class ClicksignException extends RuntimeException
{
    public function __construct(
        public readonly ClicksignErrorReason $reason,
        public readonly ?int $status = null,
        ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        $message = $reason->label();

        if ($status !== null) {
            $message .= " (HTTP {$status})";
        }

        if ($detail !== null && $detail !== '') {
            $message .= ": {$detail}";
        }

        parent::__construct($message, $status ?? 0, $previous);
    }
}
