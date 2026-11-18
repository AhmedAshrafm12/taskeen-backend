<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BuildingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'address' => $this->address,
            'floors' => $this->floors, 'notes' => $this->notes, 'is_active' => $this->is_active,
            'land_id' => $this->land_id,
            'land' => $this->whenLoaded('land', fn () => $this->land ? ['id' => $this->land->id, 'name' => $this->land->name] : null),
            'units_count' => (int) $this->units_count,
            'status_counts' => ['available' => (int) $this->available_count, 'sold' => (int) $this->sold_count, 'rented' => (int) $this->rented_count],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
