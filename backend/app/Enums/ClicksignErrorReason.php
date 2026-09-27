<?php

namespace App\Enums;

/**
 * Por que uma chamada à Clicksign falhou. Quem usa o ClicksignService
 * decide o que fazer a partir daqui (ex.: NotConfigured → 503 "integração
 * desativada"; Unavailable → tentar de novo mais tarde), sem precisar
 * interpretar texto de mensagem.
 */
enum ClicksignErrorReason: string
{
    /** Token ou URL base ausentes na configuração — nenhuma requisição é feita. */
    case NotConfigured = 'not_configured';

    /** 401: token inválido, expirado ou revogado. */
    case Unauthorized = 'unauthorized';

    /** 403: token válido, mas sem permissão para o recurso. */
    case Forbidden = 'forbidden';

    /** Falha de rede, timeout ou erro 5xx do lado da Clicksign. */
    case Unavailable = 'unavailable';

    /** Qualquer outra resposta de erro (4xx de validação, 404...). */
    case RequestFailed = 'request_failed';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Integração com a Clicksign não configurada',
            self::Unauthorized => 'Credencial da Clicksign inválida ou expirada',
            self::Forbidden => 'Credencial da Clicksign sem permissão para esta operação',
            self::Unavailable => 'Clicksign indisponível no momento',
            self::RequestFailed => 'A Clicksign recusou a requisição',
        };
    }
}
