<?php

namespace App\Models;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'location_id',
        'movement_type',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'batch_number',
        'expiry_date',
        'user_id',
        'notes',
        'reason',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_change' => 'integer',
        'quantity_after' => 'integer',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    /**
     * Movement types that increase inventory
     */
    const INCREASE_TYPES = ['purchase', 'adjustment', 'transfer_in', 'return'];

    /**
     * Movement types that decrease inventory
     */
    const DECREASE_TYPES = ['sale', 'adjustment', 'transfer_out', 'damage', 'theft', 'expired', 'promotion'];

    /**
     * Get the product that owns this movement
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the location that owns this movement
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /**
     * Get the user who created this movement
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the related reference record (polymorphic)
     */
    public function reference()
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }

    /**
     * Check if this movement increases inventory
     */
    public function isIncrease()
    {
        return $this->quantity_change > 0;
    }

    /**
     * Check if this movement decreases inventory
     */
    public function isDecrease()
    {
        return $this->quantity_change < 0;
    }

    /**
     * Scope for movements of a specific type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('movement_type', $type);
    }

    /**
     * Scope for movements within date range
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope for movements that increase stock
     */
    public function scopeIncreases($query)
    {
        return $query->where('quantity_change', '>', 0);
    }

    /**
     * Scope for movements that decrease stock
     */
    public function scopeDecreases($query)
    {
        return $query->where('quantity_change', '<', 0);
    }

    /**
     * Get movement type display name
     */
    public function getMovementTypeDisplayAttribute()
    {
        return match($this->movement_type) {
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
            default => ucfirst(str_replace('_', ' ', $this->movement_type))
        };
    }

    /**
     * Create a movement record
     */
    public static function createMovement(array $data)
    {
        // Validate required fields
        $required = ['product_id', 'location_id', 'movement_type', 'quantity_change'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Get current inventory
        $inventory = ProductInventory::where('product_id', $data['product_id'])
            ->where('location_id', $data['location_id'])
            ->first();

        $quantityBefore = $inventory ? $inventory->quantity_on_hand : 0;
        $quantityAfter = $quantityBefore + $data['quantity_change'];

        // Create the movement record
        return static::create(array_merge($data, [
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'total_cost' => isset($data['unit_cost']) ? $data['quantity_change'] * $data['unit_cost'] : null,
        ]));
    }
}