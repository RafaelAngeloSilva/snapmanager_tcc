<?php

namespace Database\Factories;

use App\Enums\GalleryStatus;
use App\Enums\WatermarkType;
use App\Models\Client;
use App\Models\Gallery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Gallery>
 */
class GalleryFactory extends Factory
{
    protected $model = Gallery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(3),
            'access_token' => Str::random(48),
            'status' => GalleryStatus::Active->value,
            'watermark_enabled' => true,
            'watermark_type' => WatermarkType::Text->value,
            'watermark_text' => Gallery::DEFAULT_WATERMARK_TEXT,
        ];
    }
}
