<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'building_id' => $this->building_id,
            'building' => $this->whenLoaded('building', fn () => $this->building ? [
                'id' => $this->building->id, 'name' => $this->building->name,
                'is_active' => $this->building->is_active,
            ] : null),
            'number' => $this->number, 'floor' => $this->floor, 'type' => $this->type,
            'area' => $this->area, 'status' => $this->resource->availability(), 'identity_locked' => $this->resource->identityLocked(),
            'asking_sale_price' => $this->asking_sale_price,
            'asking_rent_price' => $this->asking_rent_price,
            'notes' => $this->notes,
        ];
    }
}
