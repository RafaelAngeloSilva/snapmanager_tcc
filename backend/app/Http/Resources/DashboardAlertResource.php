<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardAlertResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Alerts are plain associative arrays computed on the fly (not
        // Eloquent models), so we index into $this->resource directly
        // instead of relying on JsonResource's magic property proxying.
        return [
            'type' => $this->resource['type'],
            'severity' => $this->resource['severity'],
            'title' => $this->resource['title'],
            'message' => $this->resource['message'],
            'entity_type' => $this->resource['entity_type'],
            'entity_id' => $this->resource['entity_id'],
            'date' => $this->resource['date'],
        ];
    }
}
