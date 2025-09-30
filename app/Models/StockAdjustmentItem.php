<?php

namespace App\Models;

use App\Models\Product\Product;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'system_quantity' => 'integer',
        'actual_quantity' => 'integer',
        'adjustment_quantity' => 'integer',
        'unit_cost' => 'decimal:4',
        'total_cost_change' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-calculate adjustment quantity and cost
        static::saving(function (StockAdjustmentItem $item) {
            $item->adjustment_quantity = $item->actual_quantity - $item->system_quantity;
            $item->total_cost_change = $item->adjustment_quantity * $item->unit_cost;
        });
    }

    /**
     * Get the stock adjustment that owns this item
     */
    public function stockAdjustment()
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    /**
     * Get the product that owns this item
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Check if this is an increase adjustment
     */
    public function isIncrease()
    {
        return $this->adjustment_quantity > 0;
    }

    /**
     * Check if this is a decrease adjustment
     */
    public function isDecrease()
    {
        return $this->adjustment_quantity < 0;
    }

    /**
     * Get the absolute adjustment quantity
     */
    public function getAbsoluteAdjustmentQuantity()
    {
        return abs($this->adjustment_quantity);
    }

    /**
     * Process this adjustment item and update inventory
     */
    public function processAdjustment()
    {
        if ($this->adjustment_quantity == 0) {
            return; // No adjustment needed
        }

        $inventoryService = app(InventoryService::class);
        
        // Determine movement type based on adjustment reason
        $movementType = $this->getMovementType();
        
        // Create inventory movement
        $inventoryService->recordMovement([
            'product_id' => $this->product_id,
            'location_id' => $this->stockAdjustment->location_id,
            'movement_type' => $movementType,
            'quantity_change' => $this->adjustment_quantity,
            'unit_cost' => $this->unit_cost,
            'reference_type' => StockAdjustment::class,
            'reference_id' => $this->stock_adjustment_id,
            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date,
            'user_id' => $this->stockAdjustment->approved_by,
            'notes' => $this->notes,
            'reason' => $this->stockAdjustment->reason,
        ]);
    }

    /**
     * Get movement type based on adjustment reason and quantity change
     */
    protected function getMovementType()
    {
        // If it's a decrease, determine the specific reason
        if ($this->adjustment_quantity < 0) {
            return match($this->stockAdjustment->reason) {
                'damaged_goods' => 'damage',
                'expired_goods' => 'expired',
                'theft_loss' => 'theft',
                default => 'adjustment'
            };
        }

        // If it's an increase, it's generally an adjustment
        return 'adjustment';
    }

    /**
     * Get adjustment type display
     */
    public function getAdjustmentTypeDisplayAttribute()
    {
        if ($this->adjustment_quantity > 0) {
            return 'Increase';
        } elseif ($this->adjustment_quantity < 0) {
            return 'Decrease';
        }
        
        return 'No Change';
    }

    /**
     * Get adjustment quantity with sign
     */
    public function getAdjustmentQuantityWithSignAttribute()
    {
        $sign = $this->adjustment_quantity >= 0 ? '+' : '';
        return $sign . number_format($this->adjustment_quantity);
    }
}