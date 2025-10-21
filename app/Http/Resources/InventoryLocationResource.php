<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLocationResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'type_label' => ucfirst($this->type),
            'domain' => $this->domain,
            'address' => $this->address,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Conditional includes
            'product_inventories_count' => $this->whenCounted('productInventories'),
            'inventory_movements_count' => $this->whenCounted('inventoryMovements'),
            'stock_adjustments_count' => $this->whenCounted('stockAdjustments'),
            
            // Relationships
            'product_inventories' => ProductInventoryResource::collection($this->whenLoaded('productInventories')),
            'inventory_movements' => InventoryMovementResource::collection($this->whenLoaded('inventoryMovements')),
            'stock_adjustments' => StockAdjustmentResource::collection($this->whenLoaded('stockAdjustments')),
            
            // Computed properties
            'status_badge' => [
                'text' => $this->is_active ? 'Active' : 'Inactive',
                'color' => $this->is_active ? 'success' : 'default',
            ],
            'type_badge' => [
                'text' => ucfirst($this->type),
                'color' => $this->getTypeBadgeColor(),
            ],
            'default_badge' => $this->when($this->is_default, [
                'text' => 'Default',
                'color' => 'processing',
            ]),
            
            // Summary data
            'summary' => $this->when($this->relationLoaded('productInventories'), function () {
                return [
                    'total_products' => $this->productInventories->count(),
                    'total_inventory_value' => $this->getTotalInventoryValue(),
                    'low_stock_products' => $this->getLowStockProductsCount(),
                ];
            }),
        ];
    }

    /**
     * Get badge color for location type
     */
    private function getTypeBadgeColor(): string
    {
        return match ($this->type) {
            'store' => 'blue',
            'warehouse' => 'green',
            'supplier' => 'orange',
            'customer' => 'purple',
            default => 'default',
        };
    }
}
