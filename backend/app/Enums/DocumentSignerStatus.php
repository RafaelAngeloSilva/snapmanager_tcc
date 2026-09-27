<?php

namespace App\Enums;

enum DocumentSignerStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Signed => 'Assinou',
            self::Declined => 'Recusou',
        };
    }
}
