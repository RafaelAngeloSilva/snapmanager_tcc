<?php

namespace App\Models;

use App\Enums\DocumentSignerStatus;
use App\Enums\SignerChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSigner extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'email', 'phone_number', 'channel'];

    protected function casts(): array
    {
        return [
            'channel' => SignerChannel::class,
            'status' => DocumentSignerStatus::class,
            'signed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Normaliza telefone para o formato exigido pela Clicksign: só dígitos,
     * DDD + número, sem DDI. Aceita entradas como "+55 (11) 98765-4321",
     * "5511987654321" ou "11 98765 4321". Devolve null se não sobrar um
     * número brasileiro válido (10 ou 11 dígitos).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Remove o DDI 55 quando vier junto (12 ou 13 dígitos).
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^[1-9]{2}\d{8,9}$/', $digits) ? $digits : null;
    }
}
