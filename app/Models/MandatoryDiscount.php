<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MandatoryDiscount extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'decimal:2',
    ];

    protected $searchableFields = ['name', 'type'];

    // Remove domain relationship - now using domain string column
    // public function domain()
    // {
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }
}
