<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KanbanColumnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'is_done' => $this->is_done,
            'tasks' => KanbanTaskResource::collection($this->whenLoaded('tasks')),
            'tasks_count' => $this->whenCounted('tasks'),
        ];
    }
}
