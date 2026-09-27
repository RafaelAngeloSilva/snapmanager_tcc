<?php

namespace App\Enums;

/**
 * Papel do signatário no requisito de qualificação (`action: agree`).
 * Valores aceitos pela API 3.0: sign, party, contractor.
 */
enum ClicksignSignerRole: string
{
    /** Assina (papel genérico de "assinar"). */
    case Sign = 'sign';

    /** Assina como parte do contrato. */
    case Party = 'party';

    /** Assina como contratante. */
    case Contractor = 'contractor';
}
