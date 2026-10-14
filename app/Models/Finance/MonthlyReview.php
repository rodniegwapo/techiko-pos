<?php

namespace App\Models\Finance;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/** A month's business review: the key figures, what went well, what needs attention, what to do. */
class MonthlyReview extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'figures' => 'array',
        'went_well' => 'array',
        'needs_attention' => 'array',
        'actions' => 'array',
        'generated_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    protected $appends = ['label'];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /** e.g. "September 2026". */
    public function getLabelAttribute(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->format('F Y');
    }
}
