<?php

namespace App\Models;

use App\Enums\ClientHistoryCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anotação manual da linha do tempo do cliente.
 *
 * client_id e user_id NÃO são preenchíveis em massa: vêm sempre da rota
 * (cliente) e do usuário autenticado, nunca do corpo da requisição.
 */
class ClientHistoryEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'appointment_id',
        'category',
        'title',
        'notes',
        'event_date',
        'is_testimonial_candidate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ClientHistoryCategory::class,
            'event_date' => 'date',
            'is_testimonial_candidate' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Ensaio relacionado. Ensaio excluído (soft delete) some daqui: a
     * anotação continua, só sem o vínculo exibido.
     *
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
