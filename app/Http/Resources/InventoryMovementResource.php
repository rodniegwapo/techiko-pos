<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
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
            'movement_type' => $this->movement_type,
            'movement_type_display' => $this->getMovementTypeDisplayName(),
            'quantity_change' => $this->quantity_change,
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'reference' => $this->when($this->reference_type && $this->reference_id, [
                'id' => $this->reference_id,
                'type' => $this->reference_type,
                'data' => $this->when($this->relationLoaded('reference') && $this->reference, $this->reference),
            ]),
            'notes' => $this->notes,
            'reason' => $this->reason,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'product' => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
                'SKU' => $this->product?->SKU,
                'barcode' => $this->product?->barcode,
                'unit_of_measure' => $this->product?->unit_of_measure ?? 'pcs',
            ],
            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'code' => $this->location?->code,
            ],
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ],
        ];
    }

    /**
     * Get human-readable movement type display name
     */
    private function getMovementTypeDisplayName(): string
    {
        $types = [
            'sale' => 'Sale',
            'purchase' => 'Purchase',
            'adjustment' => 'Stock Adjustment',
            'transfer_in' => 'Transfer In',
            'transfer_out' => 'Transfer Out',
            'return' => 'Customer Return',
            'damage' => 'Damaged Goods',
            'theft' => 'Theft/Loss',
            'expired' => 'Expired Products',
            'promotion' => 'Promotional Giveaway',
        ];

        return $types[$this->movement_type] ?? ucfirst(str_replace('_', ' ', $this->movement_type));
    }
}
