<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Motivo (código curto) pelo qual o login com Google não pôde ser concluído.
 * O código vai para o front-end em /login?google_error=<código>, que mostra
 * a mensagem correspondente — nunca detalhes técnicos.
 */
class GoogleLoginException extends RuntimeException
{
    public const REASONS = [
        'cancelled',        // o usuário cancelou na tela do Google
        'expired',          // sessão do login expirou / começou em outro navegador
        'invalid_state',    // state do OAuth não confere (possível CSRF)
        'invalid_request',  // início do fluxo sem o nonce exigido
        'invalid_profile',  // resposta do Google sem id/e-mail válidos
        'unverified_email', // e-mail da conta Google não verificado
        'account_conflict', // e-mail já ligado a outra conta Google
        'no_account',       // conta inexistente e cadastro público fechado
        'failed',           // qualquer outra falha (Google fora, rede...)
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Login com Google não concluído: {$reason}");
    }
}
