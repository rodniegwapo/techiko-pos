<?php

namespace App\Models\Purchasing;

use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** An order to a supplier for one store or warehouse: draft → ordered → partial → received (or cancelled). */
class PurchaseOrder extends Model
{
    public const STATUSES = ['draft', 'ordered', 'partial', 'received', 'cancelled'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'expected_date' => 'date:Y-m-d',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function bill(): HasOne
    {
        return $this->hasOne(SupplierBill::class);
    }

    /** Can still be received against. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['ordered', 'partial'], true);
    }

    /** The next number for the organization: PO-0001, PO-0002… */
    public static function nextNumber(string $domain): string
    {
        $last = static::query()->forDomain($domain)->orderByDesc('id')->value('po_number');
        $n = $last && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;

        return 'PO-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
