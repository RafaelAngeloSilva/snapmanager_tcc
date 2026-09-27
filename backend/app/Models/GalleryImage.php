<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class GalleryImage extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'gallery_id',
        'original_name',
        'stored_name',
        'path',
        'watermarked_path',
        'thumbnail_path',
        'watermarked_thumbnail_path',
        'mime_type',
        'size',
        'width',
        'height',
        'sort_order',
        'is_selected',
        'selected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_selected' => 'boolean',
            'selected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Gallery, $this>
     */
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /**
     * Assigns a runtime-only "Foto 001, Foto 002..." number to each image
     * based on position in the given (already-ordered) collection — not
     * "sort_order" directly, since deleting a photo would leave a gap
     * (1, 3, 4...) that would confuse the client/staff comparing numbers.
     * Callers must pass images already ordered by sort_order, id.
     *
     * @param  Collection<int, GalleryImage>  $images
     * @return Collection<int, GalleryImage>
     */
    public static function assignDisplayNumbers(Collection $images): Collection
    {
        $images->values()->each(function (GalleryImage $image, int $index) {
            $image->display_number = $index + 1;
        });

        return $images;
    }
}
