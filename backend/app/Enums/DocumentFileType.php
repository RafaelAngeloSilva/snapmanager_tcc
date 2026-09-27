<?php

namespace App\Enums;

/**
 * Formato do arquivo ORIGINAL enviado pelo funcionário. Só esses dois são
 * aceitos: .doc, .docm, .rtf e .odt ficam de fora de propósito.
 */
enum DocumentFileType: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Docx => 'Word',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };
    }

    /** Precisa ser convertido para PDF antes de ir à Clicksign. */
    public function needsConversion(): bool
    {
        return $this === self::Docx;
    }
}
