<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'is_active' => 'boolean',
        'payment_terms_days' => 'integer',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }
}
