<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One stretch of work: clocked in, and clocked out unless they are still on the clock. */
class TimeEntry extends Model
{
    /** An entry still open after this long means they forgot to clock out; a manager closes it. */
    public const STALE_AFTER_HOURS = 16;

    /** The longest stretch a manager can type in, so a typo can't book days of pay. */
    public const MAX_HOURS = 24;

    protected $guarded = [];

    protected $casts = [
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function edits(): HasMany
    {
        return $this->hasMany(TimeEntryEdit::class)->latest('id');
    }

    public function scopeForDomain(Builder $query, string $domain): Builder
    {
        return $query->where('domain', $domain);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clock_out_at');
    }

    /** Still on the clock, and not so long that it must have been forgotten. */
    public function scopeOnTheClock(Builder $query): Builder
    {
        return $query->whereNull('clock_out_at')
            ->where('clock_in_at', '>', now()->subHours(self::STALE_AFTER_HOURS));
    }

    /** Open for longer than a shift can be: a clock-out was forgotten. */
    public function scopeForgotten(Builder $query): Builder
    {
        return $query->whereNull('clock_out_at')
            ->where('clock_in_at', '<=', now()->subHours(self::STALE_AFTER_HOURS));
    }

    public function isForgotten(): bool
    {
        return $this->clock_out_at === null && $this->clock_in_at->lte(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /** Whole minutes worked; 0 while it is open (an open entry has no hours until it is closed). */
    public function minutes(): int
    {
        return $this->clock_out_at ? (int) $this->clock_in_at->diffInMinutes($this->clock_out_at) : 0;
    }
}
