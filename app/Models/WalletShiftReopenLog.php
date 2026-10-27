<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A closed shift that was reopened: who, why, who had ended it, and what it showed before. */
class WalletShiftReopenLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'closed_at' => 'datetime',
        'before' => 'array',
    ];

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
