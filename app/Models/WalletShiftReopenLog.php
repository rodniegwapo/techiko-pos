<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A closed shift that was reopened: who, why, and who had ended it. */
class WalletShiftReopenLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
