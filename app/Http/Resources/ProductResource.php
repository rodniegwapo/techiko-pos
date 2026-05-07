<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($this->resource->relationLoaded('inventories')) {
            if ($this->track_inventory) {
                $row = $this->inventories->first();
                $data['location_quantity_available'] = $row?->quantity_available ?? 0;
                $data['location_quantity_on_hand'] = $row?->quantity_on_hand ?? 0;
            } else {
                $data['location_quantity_available'] = null;
                $data['location_quantity_on_hand'] = null;
            }
            unset($data['inventories']);
        }

        return $data;
    }
}
