<?php

namespace App\Models\Finance;

use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money the business borrowed, and what is still owed on it. */
class Loan extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'received_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'principal' => 'decimal:2',
        'interest_rate' => 'decimal:3',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->orderBy('payment_date')->orderBy('id');
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

    /** Principal still owed as of a day (today when not given). */
    public function balanceOn(?string $date = null): float
    {
        $repaid = (float) $this->payments()
            ->when($date, fn ($q, $d) => $q->where('payment_date', '<=', $d))
            ->sum('principal');

        return round(max(0, (float) $this->principal - $repaid), 2);
    }
}
