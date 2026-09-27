<?php

namespace App\Enums;

enum DocumentType: string
{
    case MonthlyPhotoshoot = 'monthly_photoshoot';
    case Photoshoot = 'photoshoot';
    case Wedding = 'wedding';
    case ImageUseAuthorization = 'image_use_authorization';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::MonthlyPhotoshoot => 'Ensaio mensal',
            self::Photoshoot => 'Ensaio fotográfico',
            self::Wedding => 'Casamento',
            self::ImageUseAuthorization => 'Autorização de uso de imagem',
            self::Custom => 'Personalizado',
        };
    }
}
