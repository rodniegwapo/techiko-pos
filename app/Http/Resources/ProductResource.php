<?php

namespace App\Http\Resources;

use App\Support\ProductImageStorage;
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

        if (($data['representation_type'] ?? null) === 'image') {
            $data['representation'] = ProductImageStorage::displayUrl(
                $data['representation'] ?? null,
                'image',
            );
        }

        if ($this->relationLoaded('category')) {
            $data['category'] = $this->category
                ? $this->category->only(['id', 'name'])
                : null;
        }

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

        if (array_key_exists('at_location', $data)) {
            $data['at_location'] = (bool) $data['at_location'];
        }

        return $data;
    }
}
