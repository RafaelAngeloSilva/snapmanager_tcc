<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'service_type' => $this->service_type,
            'description' => $this->description,
            'due_date' => $this->due_date,
            'total' => $this->total,
            'status' => $this->status,
            'notes' => $this->notes,
            // O cliente pode ter sido soft-deleted depois que o pedido foi
            // criado: nesse caso a relação resolve como null (o global scope
            // do SoftDeletes exclui o registro), então guardamos contra isso.
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ] : null),
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'title' => $this->appointment->title,
                'starts_at' => $this->appointment->starts_at,
                'ends_at' => $this->appointment->ends_at,
            ] : null),
            'status_histories' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'notes' => $history->notes,
                'changed_by' => $history->relationLoaded('user') && $history->user ? [
                    'id' => $history->user->id,
                    'name' => $history->user->name,
                    'role' => $history->user->role,
                ] : null,
                'created_at' => $history->created_at,
            ])),
            // "Acompanhe seu Pedido": só para quem pode gerenciar o link
            // (admin/atendimento). O token nunca vai para fotógrafo/editor.
            'tracking' => $this->when(
                in_array($request->user()?->role?->value, ['admin', 'attendant'], true),
                fn () => self::trackingPayload($this->resource),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Estado do link de acompanhamento público do pedido. Também usado como
     * resposta de ativar/revogar (OrderTrackingController).
     *
     * @return array<string, mixed>
     */
    public static function trackingPayload(Order $order): array
    {
        return [
            'enabled' => $order->hasTracking(),
            'token' => $order->tracking_token,
            'enabled_at' => $order->tracking_enabled_at,
            'last_viewed_at' => $order->hasTracking() ? $order->tracking_last_viewed_at : null,
            'views' => $order->hasTracking() ? (int) $order->tracking_views : 0,
        ];
    }
}
