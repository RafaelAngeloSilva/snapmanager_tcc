<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token do Sanctum com gravação de "last_used_at" limitada.
 *
 * Por padrão o Sanctum faz um UPDATE em personal_access_tokens em TODA
 * requisição autenticada só para registrar a hora de uso. Uma tela como o
 * Dashboard dispara 4 requisições de uma vez (e uma galeria, uma por foto) —
 * são escritas desnecessárias no banco competindo com as leituras. Aqui a
 * gravação só acontece se o último registro tiver mais de 1 minuto.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    private const LAST_USED_THRESHOLD_SECONDS = 60;

    protected static function booted(): void
    {
        static::updating(function (self $token) {
            $dirty = array_keys($token->getDirty());

            if ($dirty !== ['last_used_at']) {
                return null;
            }

            $previous = $token->getOriginal('last_used_at');

            // Retornar false cancela o UPDATE; o token continua válido.
            if ($previous && now()->diffInSeconds($previous, true) < self::LAST_USED_THRESHOLD_SECONDS) {
                return false;
            }

            return null;
        });
    }
}
