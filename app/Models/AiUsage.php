<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One successful AI answer and what it cost (see AiCreditService::record). */
class AiUsage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'credits' => 'integer',
        'cost_usd' => 'decimal:6',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
