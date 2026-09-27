<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GalleryImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * "url" points at the authenticated, controlled file route — the image
     * is never exposed as a direct public file path.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'sort_order' => $this->sort_order,
            // Posição 1-indexada dentro da galeria (não o "sort_order" bruto,
            // que pode ter buracos se uma foto no meio for excluída) — ver
            // GalleryImage::assignDisplayNumbers(). Nulo se o controller que
            // montou esta resposta não numerou (ex.: retorno do upload).
            'display_number' => $this->display_number ?? null,
            'is_selected' => $this->is_selected,
            'selected_at' => $this->selected_at,
            'has_watermark' => (bool) $this->watermarked_path,
            'url' => "/galleries/{$this->gallery_id}/images/{$this->id}/file",
            // Usado pela grade interna (GalleryImagesGrid) — bem menor que
            // "url", que continua existindo pra quando o arquivo original é
            // realmente necessário.
            'thumbnail_url' => "/galleries/{$this->gallery_id}/images/{$this->id}/thumbnail",
            'created_at' => $this->created_at,
        ];
    }
}
