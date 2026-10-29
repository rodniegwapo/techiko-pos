<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cashier's shift on the till at a store. Several can be open at once (one per cashier); each
 * has its own opening, sales, count and difference. A shift whose count is off waits for a
 * manager (needs_review) without stopping anyone from selling.
 */
class RegisterShift extends Model
{
    public const OPEN = 'open';

    /** Ended and the count matched Expected. */
    public const CLOSED = 'closed';

    /** Ended with a count off from Expected: a manager looks into it. */
    public const NEEDS_REVIEW = 'needs_review';

    /** A manager checked the count. */
    public const APPROVED = 'approved';

    public const STATUSES = [self::OPEN, self::CLOSED, self::NEEDS_REVIEW, self::APPROVED];

    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'opening_cash' => 'float',
        'counted_cash' => 'float',
        'expected_cash' => 'float',
        'variance' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'collected_amount' => 'float',
        'collected_at' => 'datetime',
    ];

    public function scopeForWalletContext(Builder $query, string $domainSlug, int $locationId): Builder
    {
        return $query->where('domain', $domainSlug)->where('location_id', $locationId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /** The shift this user has open at this store, if any. */
    public static function openFor(string $domainSlug, int $locationId, int $userId): ?self
    {
        return static::query()->forWalletContext($domainSlug, $locationId)
            ->where('user_id', $userId)->open()->latest('id')->first();
    }

    /** The user's open shift, only if it belongs to this business day: an earlier day's shift takes no new money. */
    public static function openOn(string $domainSlug, int $locationId, int $userId, string $dateYmd): ?self
    {
        $shift = static::openFor($domainSlug, $locationId, $userId);

        return $shift?->business_date?->toDateString() === $dateYmd ? $shift : null;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** How the wallet pages show a shift (load user and reviewedBy first). */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'business_date' => $this->business_date?->toDateString(),
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'opening_cash' => $this->opening_cash,
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'variance' => $this->variance,
            'notes' => $this->notes,
            'reviewed_by' => $this->reviewedBy?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            // What the owner collected into Cash on hand (load collectedBy first).
            'collected_amount' => $this->collected_amount,
            'collected_by' => $this->collectedBy?->name,
            'collected_at' => $this->collected_at?->toIso8601String(),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function countSubmissions(): HasMany
    {
        return $this->hasMany(WalletCashCountSubmission::class);
    }

    public function reopenLogs(): HasMany
    {
        return $this->hasMany(WalletShiftReopenLog::class);
    }
}
