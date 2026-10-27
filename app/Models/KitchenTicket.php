<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What went to the kitchen in one send: the new lines of a tab, kept so it can be printed again. */
class KitchenTicket extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_addition' => 'boolean',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class)->orderBy('id');
    }

    /** As printed: table or name, time, who sent it, and the lines. */
    public function toPrint(string $timezone): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'label' => $this->label,
            'is_addition' => $this->is_addition,
            'sent_at' => $this->created_at->copy()->timezone($timezone)->format('M j, g:i A'),
            'sent_by' => $this->sender?->name,
            'items' => $this->items->map(fn (KitchenTicketItem $i) => [
                'name' => $i->name,
                'quantity' => (float) $i->quantity,
                'modifiers' => $i->modifiers ?? [],
                'notes' => $i->notes,
            ])->values()->all(),
        ];
    }
}
