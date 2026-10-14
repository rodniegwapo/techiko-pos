<?php

namespace App\Models\Finance;

use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashMovement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equipment, furniture, vehicles and other things the business owns and uses (not stock for sale).
 * With a useful life set, its cost is spread evenly over that time (straight-line depreciation):
 * the part used up in a period is a cost on the income statement, the rest is its book value.
 */
class FixedAsset extends Model
{
    public const CATEGORIES = ['equipment', 'furniture', 'vehicle', 'building', 'other'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'purchase_date' => 'date:Y-m-d',
        'disposed_date' => 'date:Y-m-d',
        'cost' => 'decimal:2',
        'useful_life_months' => 'integer',
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

    /** Cost used up per day while it is being depreciated (0 when no useful life is set). */
    public function dailyDepreciation(): float
    {
        if (! $this->useful_life_months) {
            return 0.0;
        }
        $lifeDays = (int) round($this->purchase_date->copy()->startOfDay()->diffInDays($this->depreciationEnd())) ?: 1;

        return (float) $this->cost / $lifeDays;
    }

    /** Cost used up between two days, inclusive. */
    public function depreciationBetween(CarbonInterface $start, CarbonInterface $end): float
    {
        if (! $this->useful_life_months) {
            return 0.0;
        }
        $from = $start->copy()->startOfDay()->max($this->purchase_date->copy()->startOfDay());
        $to = $end->copy()->startOfDay()->addDay()->min($this->depreciationEnd());
        if ($this->disposed_date) {
            $to = $to->min($this->disposed_date->copy()->startOfDay()->addDay());
        }
        if ($to->lte($from)) {
            return 0.0;
        }

        return round($this->dailyDepreciation() * (int) round($from->diffInDays($to)), 2);
    }

    /** What it is still worth on the books on a day: cost less what has been used up. */
    public function bookValueOn(CarbonInterface $date): float
    {
        if ($this->purchase_date->gt($date) || ($this->disposed_date && $this->disposed_date->lte($date))) {
            return 0.0;
        }

        return round(max(0, (float) $this->cost - $this->depreciationBetween($this->purchase_date, $date)), 2);
    }

    /** The day after its useful life ends. */
    private function depreciationEnd(): Carbon
    {
        return $this->purchase_date->copy()->startOfDay()->addMonthsNoOverflow((int) $this->useful_life_months);
    }
}
