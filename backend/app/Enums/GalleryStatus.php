<?php

namespace App\Enums;

enum GalleryStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Expired => 'Expirada',
            self::Closed => 'Encerrada',
        };
    }
}
