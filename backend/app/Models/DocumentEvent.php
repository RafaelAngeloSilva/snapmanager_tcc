<?php

namespace App\Models;

use App\Enums\DocumentEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada do histórico de um documento. Só é criada, nunca editada.
 */
class DocumentEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['event', 'description', 'metadata', 'user_id', 'dedupe_key'];

    protected function casts(): array
    {
        return [
            'event' => DocumentEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
