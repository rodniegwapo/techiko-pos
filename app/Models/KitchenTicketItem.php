<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line on a kitchen ticket, copied as it was sent (name, options, amount, note). */
class KitchenTicketItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'modifiers' => 'array',
        'quantity' => 'float',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }
}
