<?php

namespace App\Enums;

/**
 * Canal por onde o signatário recebe a solicitação de assinatura.
 *
 * As regras seguem "Campos e Regras de Negócio" do signatário na API 3.0:
 *  - signature_request aceita email | sms | whatsapp | none;
 *  - signature_reminder aceita SÓ none | email — não existe lembrete por
 *    WhatsApp, por isso o canal WhatsApp desliga o lembrete;
 *  - document_signed aceita email | whatsapp.
 * O e-mail só é obrigatório na Clicksign se algum desses valores for
 * `email`, então o signatário de WhatsApp pode não ter e-mail.
 */
enum SignerChannel: string
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Whatsapp => 'WhatsApp',
        };
    }

    /** @return array{signature_request: string, signature_reminder: string, document_signed: string} */
    public function communicateEvents(): array
    {
        return match ($this) {
            self::Email => [
                'signature_request' => 'email',
                'signature_reminder' => 'email',
                'document_signed' => 'email',
            ],
            self::Whatsapp => [
                'signature_request' => 'whatsapp',
                'signature_reminder' => 'none',
                'document_signed' => 'whatsapp',
            ],
        };
    }

    /** O token de autenticação vai pelo mesmo canal do convite. */
    public function authMethod(): ClicksignAuthMethod
    {
        return match ($this) {
            self::Email => ClicksignAuthMethod::Email,
            self::Whatsapp => ClicksignAuthMethod::Whatsapp,
        };
    }
}
