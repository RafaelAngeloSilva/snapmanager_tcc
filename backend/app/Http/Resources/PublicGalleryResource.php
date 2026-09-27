<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicGalleryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Deliberately omits anything internal/administrative (client contact
     * details, linked order, creator, soft-delete state, etc.) — only what
     * the client needs to browse and select photos.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->effectiveStatus(),
            'expires_at' => $this->expires_at,
            'is_accessible' => $this->isAccessible(),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'display_number' => $image->display_number ?? null,
                'width' => $image->width,
                'height' => $image->height,
                'is_selected' => $image->is_selected,
                // "url" (versão cheia, com marca d'água) alimenta o
                // lightbox; "thumbnail_url" (bem menor, também com marca
                // d'água quando aplicável) alimenta a grade — a grade nunca
                // baixa a versão em tamanho real só pra mostrar uma célula
                // pequena.
                'url' => url("/api/public/galleries/{$this->access_token}/images/{$image->id}"),
                'thumbnail_url' => url("/api/public/galleries/{$this->access_token}/images/{$image->id}/thumbnail"),
            ])),
        ];
    }
}
