<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Carbon;

/**
 * Converte `expires_at` para o fuso da aplicação (UTC) ANTES da validação.
 *
 * Por quê: o Eloquent grava datas com format('Y-m-d H:i:s') numa coluna sem
 * fuso. Uma data com offset ("2026-10-01T18:00:00-03:00") seria gravada
 * como 18:00 — o horário de parede, não o instante — e a galeria expiraria
 * três horas antes do combinado. Normalizando aqui, tanto faz o formato que
 * o cliente da API mandar (com "Z", com offset ou sem fuso): o instante
 * gravado é o escolhido, e as regras de "não pode ser no passado" comparam
 * a mesma coisa.
 */
trait NormalizesExpiration
{
    protected function prepareForValidation(): void
    {
        $expiresAt = $this->input('expires_at');

        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return;
        }

        try {
            $this->merge([
                'expires_at' => Carbon::parse($expiresAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Formato inválido: deixa a regra "date" recusar com a mensagem certa.
        }
    }
}
