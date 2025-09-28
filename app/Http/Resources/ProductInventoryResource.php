<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductInventoryResource extends JsonResource
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
            'quantity_on_hand' => $this->quantity_on_hand,
            'quantity_available' => $this->quantity_available,
            'quantity_reserved' => $this->quantity_reserved,
            'quantity_committed' => $this->quantity_committed,
            'reorder_level' => $this->reorder_level,
            'max_stock_level' => $this->max_stock_level,
            'unit_cost' => $this->unit_cost,
            'total_value' => $this->total_value,
            'last_movement_at' => $this->last_movement_at,
            'location_stock_status' => $this->location_stock_status ?? $this->getStockStatus(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'product' => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
                'SKU' => $this->product?->SKU,
                'barcode' => $this->product?->barcode,
                'unit_of_measure' => $this->product?->unit_of_measure ?? 'pcs',
                'price' => $this->product?->price,
                'cost' => $this->product?->cost,
                'category' => [
                    'id' => $this->product?->category?->id,
                    'name' => $this->product?->category?->name,
                ],
            ],
            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'code' => $this->location?->code,
                'address' => $this->location?->address,
            ],
        ];
    }
}
