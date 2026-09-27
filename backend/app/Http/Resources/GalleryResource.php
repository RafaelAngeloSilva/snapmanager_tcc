<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GalleryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->effectiveStatus(),
            'access_token' => $this->access_token,
            'expires_at' => $this->expires_at,
            'closed_at' => $this->closed_at,
            'selection_completed_at' => $this->selection_completed_at,
            'watermark_enabled' => $this->watermark_enabled,
            'watermark_type' => $this->watermark_type,
            'watermark_text' => $this->watermark_text,
            'watermark_image_url' => $this->watermark_image_path
                ? "/galleries/{$this->id}/watermark-image/file"
                : null,
            'images_count' => $this->whenCounted('images'),
            // "selected_images_count" vem de withCount() na listagem (leve,
            // sem carregar as fotos inteiras); na tela de detalhes, onde as
            // fotos já vêm carregadas, calcula direto da coleção.
            'selected_count' => isset($this->selected_images_count)
                ? (int) $this->selected_images_count
                : $this->when(
                    $this->relationLoaded('images'),
                    fn () => $this->images->where('is_selected', true)->count()
                ),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
                // Usado pelo botão "Enviar por WhatsApp" da tela de detalhes
                // (monta o link wa.me). Rota interna, atrás de auth:sanctum —
                // a galeria pública continua sem nenhum dado de contato.
                'phone' => $this->client->phone,
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'title' => $this->order->title,
            ] : null),
            'created_by' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'images' => GalleryImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
