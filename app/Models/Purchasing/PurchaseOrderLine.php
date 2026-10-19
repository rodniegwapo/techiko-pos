<?php

namespace App\Models\Purchasing;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'quantity_ordered' => 'float',
        'quantity_received' => 'float',
        'unit_cost' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remaining(): float
    {
        return max(0, round($this->quantity_ordered - $this->quantity_received, 2));
    }
}
