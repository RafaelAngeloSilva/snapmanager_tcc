<?php

namespace App\Models;

use App\Enums\GalleryStatus;
use App\Enums\WatermarkType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Gallery extends Model
{
    use HasFactory, SoftDeletes;

    public const DEFAULT_WATERMARK_TEXT = 'PROIBIDO REPRODUÇÃO';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'order_id',
        'user_id',
        'title',
        'description',
        'access_token',
        'status',
        'expires_at',
        'closed_at',
        'selection_completed_at',
        'watermark_enabled',
        'watermark_type',
        'watermark_text',
        'watermark_image_path',
    ];

    protected static function booted(): void
    {
        static::creating(function (Gallery $gallery) {
            if (! $gallery->access_token) {
                $gallery->access_token = Str::random(48);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'selection_completed_at' => 'datetime',
            'watermark_enabled' => 'boolean',
            'status' => GalleryStatus::class,
            'watermark_type' => WatermarkType::class,
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GalleryImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class);
    }

    /**
     * Status accounting for an expiration date that has passed but that the
     * "galleries:expire" command has not yet swept (the command is what
     * persists the "expired" status; this just reflects it defensively so
     * the public page never shows an accessible gallery past its date).
     */
    public function effectiveStatus(): GalleryStatus
    {
        if ($this->status === GalleryStatus::Active && $this->expires_at && $this->expires_at->isPast()) {
            return GalleryStatus::Expired;
        }

        return $this->status;
    }

    public function isAccessible(): bool
    {
        return $this->effectiveStatus() === GalleryStatus::Active;
    }

    public function hasCompletedSelection(): bool
    {
        return $this->selection_completed_at !== null;
    }

    /**
     * Whether new uploads to this gallery should be watermarked, and there's
     * an actual source (text or a custom image) configured to apply.
     */
    public function shouldWatermark(): bool
    {
        if (! $this->watermark_enabled || $this->watermark_type === WatermarkType::None) {
            return false;
        }

        if ($this->watermark_type === WatermarkType::Text) {
            return filled($this->watermark_text);
        }

        return $this->watermark_type === WatermarkType::Image && filled($this->watermark_image_path);
    }
}
