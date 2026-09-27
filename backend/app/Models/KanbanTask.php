<?php

namespace App\Models;

use App\Enums\KanbanPriority;
use App\Enums\KanbanTaskOrigin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KanbanTask extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kanban_column_id',
        'title',
        'description',
        'assigned_to',
        'priority',
        'due_date',
        'position',
        'client_id',
        'appointment_id',
        'order_id',
        'gallery_id',
        'completed_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => KanbanPriority::class,
            'due_date' => 'date',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Origem derivada das FKs, em vez de uma coluna source_type duplicando
     * essa informação: se o card aponta para um agendamento, ele nasceu da
     * agenda; se aponta para um pedido, nasceu do pedido; senão é uma
     * demanda avulsa criada direto no quadro.
     */
    public function origin(): KanbanTaskOrigin
    {
        return match (true) {
            $this->appointment_id !== null => KanbanTaskOrigin::Appointment,
            $this->order_id !== null => KanbanTaskOrigin::Order,
            default => KanbanTaskOrigin::Manual,
        };
    }

    /**
     * @return BelongsTo<KanbanColumn, $this>
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(KanbanColumn::class, 'kanban_column_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Gallery, $this>
     */
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }
}
