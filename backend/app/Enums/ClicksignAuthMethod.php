<?php

namespace App\Enums;

/**
 * Como o signatário comprova a identidade (requisito `provide_evidence`).
 *
 * A API 3.0 aceita mais opções (pix, selfie, icp_brasil, biometrias...),
 * mas várias têm custo adicional ou exigem configuração da conta. Aqui
 * ficam só as que dependem apenas do contato do signatário; novas opções
 * entram como novos cases, com o valor exato da documentação.
 */
enum ClicksignAuthMethod: string
{
    /** Token enviado por e-mail — exige `email` no signatário. */
    case Email = 'email';

    /** Token por SMS — exige `phone_number` no signatário. */
    case Sms = 'sms';

    /** Token por WhatsApp — exige `phone_number` no signatário. */
    case Whatsapp = 'whatsapp';
}
