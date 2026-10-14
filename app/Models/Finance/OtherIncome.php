<?php

namespace App\Models\Finance;

use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money earned outside of sales: renting out space, interest, a supplier refund, and so on. */
class OtherIncome extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'income_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletCashMovement(): BelongsTo
    {
        return $this->belongsTo(WalletCashMovement::class);
    }
}
