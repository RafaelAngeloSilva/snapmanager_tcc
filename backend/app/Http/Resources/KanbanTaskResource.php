<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumo enxuto de um card. Dos relacionamentos, só o que o card/modal
 * mostra: nome do cliente, nome+perfil+foto do responsável, e o mínimo do
 * agendamento/pedido/galeria de origem para montar o link "ver registro".
 * Nada de histórico de status, fotos da galeria ou payload completo dos
 * relacionamentos.
 */
class KanbanTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kanban_column_id' => $this->kanban_column_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'priority_label' => $this->priority?->label(),
            'due_date' => $this->due_date,
            'position' => $this->position,
            'completed_at' => $this->completed_at,
            'origin' => $this->origin(),
            'origin_label' => $this->origin()->label(),

            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'role' => $this->assignee->role,
                'profile_photo_url' => $this->assignee->profile_photo_url,
            ] : null),

            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ] : null),

            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'title' => $this->appointment->title,
                'session_type' => $this->appointment->session_type,
                'starts_at' => $this->appointment->starts_at,
                'ends_at' => $this->appointment->ends_at,
            ] : null),

            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'title' => $this->order->title,
                'status' => $this->order->status,
                'due_date' => $this->order->due_date,
            ] : null),

            'gallery' => $this->whenLoaded('gallery', fn () => $this->gallery ? [
                'id' => $this->gallery->id,
                'title' => $this->gallery->title,
            ] : null),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
