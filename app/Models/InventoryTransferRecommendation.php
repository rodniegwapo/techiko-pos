<?php

namespace App\Models;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferRecommendation extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'recommended_quantity' => 'integer',
        'current_stock_from' => 'integer',
        'current_stock_to' => 'integer',
        'days_of_stock_remaining' => 'integer',
        'demand_velocity_to' => 'decimal:2',
        'potential_lost_sales' => 'decimal:4',
        'recommended_at' => 'datetime',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Priority levels with numeric values for sorting
     */
    const PRIORITY_VALUES = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'urgent' => 4,
    ];

    /**
     * Get the product that owns this recommendation
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the source location
     */
    public function fromLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    /**
     * Get the destination location
     */
    public function toLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }

    /**
     * Get the user who approved this recommendation
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who processed this recommendation
     */
    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Scope for pending recommendations
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for high priority recommendations
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'urgent']);
    }

    /**
     * Scope for urgent recommendations
     */
    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    /**
     * Scope for recommendations that haven't expired
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope for recommendations by location
     */
    public function scopeForLocation($query, $locationId, $direction = 'to')
    {
        $column = $direction === 'to' ? 'to_location_id' : 'from_location_id';
        return $query->where($column, $locationId);
    }

    /**
     * Get priority display name
     */
    public function getPriorityDisplayAttribute()
    {
        return match($this->priority) {
            'low' => 'Low Priority',
            'medium' => 'Medium Priority',
            'high' => 'High Priority',
            'urgent' => 'Urgent',
            default => ucfirst($this->priority)
        };
    }

    /**
     * Get reason display name
     */
    public function getReasonDisplayAttribute()
    {
        return match($this->reason) {
            'low_stock' => 'Low Stock Alert',
            'out_of_stock' => 'Out of Stock',
            'excess_stock' => 'Excess Stock Available',
            'demand_pattern' => 'Based on Demand Pattern',
            'seasonal_demand' => 'Seasonal Demand',
            'promotion_prep' => 'Promotion Preparation',
            'manual_request' => 'Manual Request',
            default => ucfirst(str_replace('_', ' ', $this->reason))
        };
    }

    /**
     * Get priority color for UI
     */
    public function getPriorityColorAttribute()
    {
        return match($this->priority) {
            'low' => 'blue',
            'medium' => 'orange',
            'high' => 'red',
            'urgent' => 'purple',
            default => 'gray'
        };
    }

    /**
     * Check if recommendation is expired
     */
    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if recommendation is actionable
     */
    public function isActionable()
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    /**
     * Approve the recommendation
     */
    public function approve($userId = null)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $userId ?? auth()->id(),
            'approved_at' => now(),
        ]);

        return $this;
    }

    /**
     * Reject the recommendation
     */
    public function reject($userId = null, $notes = null)
    {
        $this->update([
            'status' => 'rejected',
            'processed_by' => $userId ?? auth()->id(),
            'processed_at' => now(),
            'notes' => $notes,
        ]);

        return $this;
    }

    /**
     * Mark as completed
     */
    public function complete($userId = null)
    {
        $this->update([
            'status' => 'completed',
            'processed_by' => $userId ?? auth()->id(),
            'processed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Calculate potential savings from this transfer
     */
    public function getPotentialSavingsAttribute()
    {
        // Calculate based on potential lost sales prevented
        return $this->potential_lost_sales;
    }

    /**
     * Get urgency score for sorting
     */
    public function getUrgencyScoreAttribute()
    {
        $priorityScore = self::PRIORITY_VALUES[$this->priority] ?? 0;
        $timeScore = $this->days_of_stock_remaining <= 0 ? 10 : max(0, 10 - $this->days_of_stock_remaining);
        $salesScore = min(10, $this->potential_lost_sales / 1000); // Normalize to 0-10

        return $priorityScore * 3 + $timeScore * 2 + $salesScore;
    }
}