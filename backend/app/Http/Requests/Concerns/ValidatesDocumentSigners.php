<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SignerChannel;
use App\Models\DocumentSigner;
use App\Support\FieldLimits;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Regras de signatário compartilhadas entre "criar documento" (lista de
 * signatários) e "adicionar signatário". Seguem as regras da Clicksign:
 *  - nome com ao menos nome e sobrenome, sem números;
 *  - canal e-mail exige e-mail; canal WhatsApp exige telefone;
 *  - telefone brasileiro, DDD + número (o formato final, só dígitos e sem
 *    DDI, é aplicado por DocumentSigner::normalizePhone ao salvar).
 */
trait ValidatesDocumentSigners
{
    /**
     * @return array<string, mixed>
     */
    protected function signerRules(string $prefix = ''): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'max:'.FieldLimits::PERSON_NAME, 'regex:/^[\pL\'\.\-]+(\s+[\pL\'\.\-]+)+$/u'],
            "{$prefix}channel" => ['required', Rule::enum(SignerChannel::class)],
            "{$prefix}email" => ['nullable', 'required_if:'.$prefix.'channel,'.SignerChannel::Email->value, 'email', 'max:'.FieldLimits::EMAIL],
            "{$prefix}phone_number" => [
                'nullable',
                'required_if:'.$prefix.'channel,'.SignerChannel::Whatsapp->value,
                'string',
                'max:30',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value !== null && $value !== '' && DocumentSigner::normalizePhone((string) $value) === null) {
                        $fail('Telefone inválido. Informe DDD + número, ex.: (11) 98765-4321.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function signerMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}name.required" => 'Informe o nome completo do signatário.',
            "{$prefix}name.regex" => 'Informe nome e sobrenome, sem números (exigência da Clicksign).',
            "{$prefix}channel.required" => 'Escolha o canal de envio.',
            "{$prefix}email.required_if" => 'O e-mail é obrigatório para envio por e-mail.',
            "{$prefix}email.email" => 'E-mail inválido.',
            "{$prefix}phone_number.required_if" => 'O telefone é obrigatório para envio por WhatsApp.',
        ];
    }
}
