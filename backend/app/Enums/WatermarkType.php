<?php

namespace App\Enums;

enum WatermarkType: string
{
    case None = 'none';
    case Text = 'text';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem marca d\'água',
            self::Text => 'Texto genérico',
            self::Image => 'Imagem personalizada',
        };
    }
}
