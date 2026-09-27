<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'appointment_id',
        'title',
        'service_type',
        'description',
        'due_date',
        'total',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'total' => 'decimal:2',
            'status' => OrderStatus::class,
            'tracking_enabled_at' => 'datetime',
            'tracking_last_viewed_at' => 'datetime',
            'tracking_views' => 'integer',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return HasMany<StatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Acompanhe seu Pedido (link público de acompanhamento)
    |--------------------------------------------------------------------------
    | As colunas tracking_* NÃO estão em $fillable de propósito: só mudam por
    | estes métodos, nunca pelo formulário de edição do pedido.
    */

    public function hasTracking(): bool
    {
        return $this->tracking_token !== null;
    }

    /**
     * Ativa o acompanhamento. Idempotente: se já existe um link ativo, mantém
     * o mesmo token (o cliente que já recebeu o link não perde o acesso).
     */
    public function enableTracking(): void
    {
        if ($this->hasTracking()) {
            return;
        }

        do {
            $token = Str::random(40);
        } while (static::withTrashed()->where('tracking_token', $token)->exists());

        $this->forceFill([
            'tracking_token' => $token,
            'tracking_enabled_at' => now(),
            'tracking_last_viewed_at' => null,
            'tracking_views' => 0,
        ])->save();
    }

    /** Revoga: o token é apagado e o link deixa de funcionar imediatamente. */
    public function revokeTracking(): void
    {
        $this->forceFill([
            'tracking_token' => null,
            'tracking_enabled_at' => null,
        ])->save();
    }

    /** Registra uma visualização do cliente sem mexer em updated_at do pedido. */
    public function registerTrackingView(): void
    {
        $this->timestamps = false;
        $this->forceFill([
            'tracking_last_viewed_at' => now(),
            'tracking_views' => $this->tracking_views + 1,
        ])->saveQuietly();
        $this->timestamps = true;
    }
}
