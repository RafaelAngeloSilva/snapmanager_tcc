<?php

namespace App\Models;

use App\Enums\DocumentConversionStatus;
use App\Enums\DocumentEventType;
use App\Enums\DocumentFileType;
use App\Enums\DocumentSignerStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;

class Document extends Model
{
    use SoftDeletes;

    /** Relacionamentos da tela de detalhes (a listagem carrega bem menos). */
    public const DETAIL_RELATIONS = [
        'client:id,name',
        'order:id,title',
        'appointment:id,title,starts_at',
        'creator:id,name',
        'signers',
        'events.user:id,name',
    ];

    /**
     * `status` e os IDs externos NÃO estão aqui: só o próprio back-end os
     * altera (envio, webhook, sincronização). Nada que venha do front-end
     * consegue definir o estado de assinatura de um documento.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'order_id',
        'appointment_id',
        'title',
        'type',
        'notes',
        'expires_at',
        'original_file_path',
        'original_file_name',
        'original_file_size',
        'original_file_type',
        'created_by',
    ];

    /**
     * Caminhos no disco nunca saem na serialização — nem por engano, se
     * alguém devolver o model cru numa resposta.
     *
     * @var list<string>
     */
    protected $hidden = ['original_file_path', 'signing_file_path', 'signed_file_path'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'sent_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'date',
            'original_file_size' => 'integer',
            'original_file_type' => DocumentFileType::class,
            'signing_file_size' => 'integer',
            'conversion_status' => DocumentConversionStatus::class,
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DocumentSigner, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(DocumentSigner::class)->orderBy('id');
    }

    /** @return HasMany<DocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Quem enxerga o documento. É a MESMA regra usada pela listagem e pela
     * DocumentPolicy::view — por isso um id adivinhado na URL nunca mostra
     * um documento que a listagem esconderia (proteção contra IDOR).
     *
     * Segue a matriz de acesso que já existe no sistema:
     *  - admin/atendimento: todos;
     *  - fotógrafo: já lê clientes, agenda e pedidos -> todos (só leitura);
     *  - editor: lê pedidos, mas NÃO clientes nem agenda -> só documentos
     *    vinculados a um pedido;
     *  - qualquer outro perfil: nenhum.
     *
     * @param  Builder<Document>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin, UserRole::Attendant, UserRole::Photographer => null,
            UserRole::Editor => $query->whereNotNull('order_id'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return match ($user->role) {
            UserRole::Admin, UserRole::Attendant, UserRole::Photographer => true,
            UserRole::Editor => $this->order_id !== null,
            default => false,
        };
    }

    /**
     * O PDF que vai para a Clicksign. Original PDF: o próprio original.
     * Original DOCX: o PDF convertido — ou null enquanto a conversão não
     * tiver sido concluída (e aí o documento não pode ser enviado).
     */
    public function signingFilePath(): ?string
    {
        if (! $this->original_file_type->needsConversion()) {
            return $this->original_file_path;
        }

        return $this->conversion_status === DocumentConversionStatus::Converted ? $this->signing_file_path : null;
    }

    public function signingFileSize(): ?int
    {
        return $this->original_file_type->needsConversion() ? $this->signing_file_size : $this->original_file_size;
    }

    public function isReadyForSigning(): bool
    {
        return $this->signingFilePath() !== null;
    }

    public function signedCount(): int
    {
        return $this->signers->where('status', DocumentSignerStatus::Signed)->count();
    }

    /**
     * Registra uma entrada no histórico.
     *
     * Com `$dedupeKey`, o registro é idempotente: se já existir um evento
     * com a mesma chave para este documento, nada é gravado e o método
     * devolve false. O índice único no banco garante isso mesmo com duas
     * requisições simultâneas (ex.: webhook reenviado + sincronização).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordEvent(
        DocumentEventType $event,
        ?string $description = null,
        ?array $metadata = null,
        ?User $user = null,
        ?string $dedupeKey = null,
    ): bool {
        if ($dedupeKey !== null && $this->hasEvent($dedupeKey)) {
            return false;
        }

        try {
            $this->events()->create([
                'event' => $event,
                'description' => $description,
                'metadata' => $metadata,
                'user_id' => $user?->id,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function hasEvent(string $dedupeKey): bool
    {
        return $this->events()->where('dedupe_key', $dedupeKey)->exists();
    }
}
