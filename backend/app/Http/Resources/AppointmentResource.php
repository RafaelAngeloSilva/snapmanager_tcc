<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
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
            'session_type' => $this->session_type,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'location' => $this->location,
            'status' => $this->status,
            'notes' => $this->notes,
            // Guarda contra cliente soft-deleted (relação resolve como null).
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
