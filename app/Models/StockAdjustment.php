<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'adjustment_number',
        'location_id',
        'type',
        'reason',
        'description',
        'total_value_change',
        'status',
        'approved_at',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'total_value_change' => 'decimal:4',
        'approved_at' => 'datetime',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate adjustment number
        static::creating(function (StockAdjustment $adjustment) {
            if (!$adjustment->adjustment_number) {
                $adjustment->adjustment_number = static::generateAdjustmentNumber();
            }
        });
    }

    /**
     * Get the location that owns this adjustment
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /**
     * Get the user who created this adjustment
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved this adjustment
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the adjustment items
     */
    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    /**
     * Scope for pending adjustments
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending_approval');
    }

    /**
     * Scope for approved adjustments
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Check if adjustment can be approved
     */
    public function canBeApproved()
    {
        return in_array($this->status, ['draft', 'pending_approval']);
    }

    /**
     * Approve the adjustment
     */
    public function approve(User $approver)
    {
        if (!$this->canBeApproved()) {
            throw new \Exception('Adjustment cannot be approved in current status: ' . $this->status);
        }

        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $approver->id,
        ]);

        // Process the adjustment items
        $this->processAdjustmentItems();

        return $this;
    }

    /**
     * Reject the adjustment
     */
    public function reject()
    {
        if (!in_array($this->status, ['draft', 'pending_approval'])) {
            throw new \Exception('Adjustment cannot be rejected in current status: ' . $this->status);
        }

        $this->update(['status' => 'rejected']);
        return $this;
    }

    /**
     * Submit for approval
     */
    public function submitForApproval()
    {
        if ($this->status !== 'draft') {
            throw new \Exception('Only draft adjustments can be submitted for approval');
        }

        $this->update(['status' => 'pending_approval']);
        return $this;
    }

    /**
     * Calculate total value change from items
     */
    public function calculateTotalValueChange()
    {
        $total = $this->items()->sum('total_cost_change');
        $this->update(['total_value_change' => $total]);
        return $total;
    }

    /**
     * Process adjustment items and update inventory
     */
    protected function processAdjustmentItems()
    {
        foreach ($this->items as $item) {
            $item->processAdjustment();
        }
    }

    /**
     * Generate unique adjustment number
     */
    protected static function generateAdjustmentNumber()
    {
        $year = date('Y');
        $lastAdjustment = static::where('adjustment_number', 'like', "ADJ-{$year}-%")
            ->orderBy('adjustment_number', 'desc')
            ->first();

        if ($lastAdjustment) {
            $lastNumber = (int) substr($lastAdjustment->adjustment_number, -3);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('ADJ-%s-%03d', $year, $nextNumber);
    }

    /**
     * Get status display name
     */
    public function getStatusDisplayAttribute()
    {
        return match($this->status) {
            'draft' => 'Draft',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($this->status)
        };
    }

    /**
     * Get reason display name
     */
    public function getReasonDisplayAttribute()
    {
        return match($this->reason) {
            'physical_count' => 'Physical Count',
            'damaged_goods' => 'Damaged Goods',
            'expired_goods' => 'Expired Goods',
            'theft_loss' => 'Theft/Loss',
            'supplier_error' => 'Supplier Error',
            'system_error' => 'System Error',
            'promotion' => 'Promotion',
            'sample' => 'Sample',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', $this->reason))
        };
    }
}