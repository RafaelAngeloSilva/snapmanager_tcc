<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'document',
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'notes',
    ];

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Status de agendamento que ainda estão "em aberto" (não aconteceram nem
     * foram cancelados). Concluídos e cancelados são histórico e nunca
     * mudam automaticamente.
     *
     * @var list<AppointmentStatus>
     */
    public const PENDING_APPOINTMENT_STATUSES = [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed];

    /**
     * Exclui o cliente (soft delete) e cancela, na MESMA transação, todos os
     * agendamentos dele ainda em aberto — passados ou futuros: um ensaio de
     * cliente excluído não pode continuar ocupando a agenda nem aparecer
     * como agendado. Devolve quantos agendamentos foram cancelados.
     *
     * Tudo-ou-nada: se o soft delete falhar, os cancelamentos são desfeitos.
     * Limite conhecido: um agendamento criado no MESMO instante da exclusão
     * (janela de milissegundos) pode escapar — a criação valida que o
     * cliente não está excluído, mas sem esperar esta transação.
     */
    public function deleteAndCancelPendingAppointments(): int
    {
        return DB::transaction(function () {
            $cancelled = $this->appointments()
                ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, self::PENDING_APPOINTMENT_STATUSES))
                ->update(['status' => AppointmentStatus::Cancelled->value, 'updated_at' => now()]);

            $this->delete();

            return $cancelled;
        });
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Gallery, $this>
     */
    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }
}
