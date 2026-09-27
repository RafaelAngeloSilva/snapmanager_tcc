<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrada do histórico. `metadata` e `dedupe_key` são internos e não saem
 * na API.
 */
class DocumentEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'label' => $this->event->label(),
            'description' => $this->description,
            // Sem usuário = veio da Clicksign (webhook) ou do sistema.
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'created_at' => $this->created_at,
        ];
    }
}
