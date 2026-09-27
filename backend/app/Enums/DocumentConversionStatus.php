<?php

namespace App\Enums;

/**
 * Situação da conversão DOCX → PDF. Não existe para documentos PDF.
 */
enum DocumentConversionStatus: string
{
    case Converted = 'converted';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Converted => 'Conversão concluída',
            self::Failed => 'Conversão falhou',
        };
    }
}
