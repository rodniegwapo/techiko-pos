<?php

namespace App\Support\Wallet;

use App\Models\CashOnHandMovement;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\RegisterShift;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Support\FinanceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cash on hand: the business's cash the owner holds, out of every drawer. It is still the
 * business's money, so collecting it and moving it back to a store only change where it is (a
 * drawer line and a Cash on hand line sharing a transfer_group, like a transfer). "Owner used it"
 * is the one line that takes it out of the business. Only owners and admins move it.
 */
class CashOnHand
{
    public static function balance(string $domainSlug): float
    {
        $sum = CashOnHandMovement::query()->forDomain($domainSlug)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN -amount ELSE amount END), 0) AS balance")
            ->value('balance');

        return round((float) $sum, 2);
    }

    /**
     * What the owner kept for themselves out of Cash on hand ("Owner used it"), between two dates
     * or ever: the owner withdrawals that don't come straight from a drawer.
     */
    public static function ownerUseTotal(string $domainSlug, ?string $from = null, ?string $to = null): float
    {
        return round((float) CashOnHandMovement::query()->forDomain($domainSlug)
            ->where('kind', CashOnHandMovement::OWNER_USE)
            ->when($from, fn ($q, $d) => $q->whereDate('movement_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('movement_date', '<=', $d))
            ->sum('amount'), 2);
    }

    /** Shifts a manager approved whose cash the owner hasn't collected yet, newest first. */
    public static function waitingToCollect(string $domainSlug)
    {
        return RegisterShift::query()->where('domain', $domainSlug)
            ->where('status', RegisterShift::APPROVED)
            ->whereNull('collected_at')
            ->where('counted_cash', '>', 0)
            ->with(['user:id,name', 'reviewedBy:id,name', 'collectedBy:id,name', 'location:id,name'])
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get()
            // A day already cashed out at end of day went into Cash on hand that way.
            ->reject(fn (RegisterShift $s) => self::dayCashedOut($domainSlug, (int) $s->location_id, $s->business_date->toDateString()))
            ->map(fn (RegisterShift $s) => [...$s->toRow(), 'store' => $s->location?->name, 'location_id' => (int) $s->location_id])
            ->values();
    }

    /**
     * The owner collects an approved shift's cash from the store's drawer: it leaves the drawer
     * today and joins Cash on hand. Once per shift; up to what was counted.
     */
    public function collectShift(Domain $domain, RegisterShift $shift, User $actor, float $amount, ?string $note = null): CashOnHandMovement
    {
        $this->ensureOwner($actor);
        $amount = round($amount, 2);
        $today = now()->toDateString();

        return DB::transaction(function () use ($domain, $shift, $actor, $amount, $note, $today) {
            $shift = RegisterShift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($shift->status !== RegisterShift::APPROVED) {
                throw ValidationException::withMessages(['shift' => 'A manager approves the count before the cash is collected.']);
            }
            if ($shift->collected_at !== null) {
                throw ValidationException::withMessages(['shift' => 'This shift\'s cash was already collected.']);
            }
            if ($amount <= 0 || $amount > (float) $shift->counted_cash) {
                throw ValidationException::withMessages(['amount' => 'Collect more than zero and no more than the ₱'.number_format((float) $shift->counted_cash, 2).' counted.']);
            }
            if (self::dayCashedOut($domain->name_slug, (int) $shift->location_id, $shift->business_date->toDateString())) {
                throw ValidationException::withMessages(['shift' => 'This day\'s cash was already cashed out to Cash on hand when the store\'s day ended.']);
            }
            WalletShiftGuard::ensureDateNotClosed($domain->name_slug, (int) $shift->location_id, $today, 'shift');

            $store = InventoryLocation::query()->findOrFail($shift->location_id);
            $group = (string) Str::uuid();
            $who = $shift->user?->name ?? 'a cashier';
            $text = fn (string $line) => Str::limit($line.(filled($note) ? ': '.trim($note) : ''), 2000);

            $this->storeLine($domain, $store, null, 'out', $amount, WalletCashMovement::KIND_TO_CASH_ON_HAND, $group, $text("To Cash on hand ({$who}'s shift)"), $today, $actor);
            $line = CashOnHandMovement::query()->create([
                'domain' => $domain->name_slug,
                'direction' => 'in',
                'amount' => $amount,
                'kind' => CashOnHandMovement::COLLECTED,
                'location_id' => $store->id,
                'register_shift_id' => $shift->id,
                'source_date' => $shift->business_date->toDateString(),
                'transfer_group' => $group,
                'movement_date' => $today,
                'notes' => $text("From {$store->name}, {$who}'s shift"),
                'user_id' => $actor->id,
            ]);

            $shift->update(['collected_amount' => $amount, 'collected_by' => $actor->id, 'collected_at' => now()]);

            return $line;
        });
    }

    /**
     * The store's end-of-day cash-out goes into Cash on hand (the drawer's side of it is written by
     * the end of day). Whoever ended the day is on the line.
     */
    public function recordEndOfDayCashOut(Domain $domain, InventoryLocation $store, string $businessDate, float $amount, int $userId, string $group): CashOnHandMovement
    {
        return CashOnHandMovement::query()->create([
            'domain' => $domain->name_slug,
            'direction' => 'in',
            'amount' => round($amount, 2),
            'kind' => CashOnHandMovement::COLLECTED,
            'location_id' => $store->id,
            'source_date' => $businessDate,
            'transfer_group' => $group,
            'movement_date' => now()->toDateString(),
            'notes' => "End of day at {$store->name}",
            'user_id' => $userId,
        ]);
    }

    /** Cash on hand back into a store's drawer, or one of its payment channels (a deposit). */
    public function toStore(Domain $domain, User $actor, InventoryLocation $store, ?PaymentCardType $channel, float $amount, ?string $note = null): CashOnHandMovement
    {
        $this->ensureOwner($actor);
        $amount = round($amount, 2);
        $today = now()->toDateString();
        WalletShiftGuard::ensureDateNotClosed($domain->name_slug, (int) $store->id, $today, 'location_id');

        return DB::transaction(function () use ($domain, $actor, $store, $channel, $amount, $note, $today) {
            $this->ensureEnough($domain, $amount);
            $group = (string) Str::uuid();
            $where = $channel ? "{$channel->name} ({$store->name})" : "{$store->name}'s drawer";
            $text = fn (string $line) => Str::limit($line.(filled($note) ? ': '.trim($note) : ''), 2000);

            $this->storeLine($domain, $store, $channel, 'in', $amount, WalletCashMovement::KIND_FROM_CASH_ON_HAND, $group, $text('From Cash on hand'), $today, $actor);

            return CashOnHandMovement::query()->create([
                'domain' => $domain->name_slug,
                'direction' => 'out',
                'amount' => $amount,
                'kind' => CashOnHandMovement::TO_STORE,
                'location_id' => $store->id,
                'payment_card_type_id' => $channel?->id,
                'transfer_group' => $group,
                'movement_date' => $today,
                'notes' => $text("To {$where}"),
                'user_id' => $actor->id,
            ]);
        });
    }

    /** The owner kept it for themselves: the one line that takes money out of the business. */
    public function ownerUse(Domain $domain, User $actor, float $amount, ?string $note = null): CashOnHandMovement
    {
        $this->ensureOwner($actor);

        return DB::transaction(function () use ($domain, $actor, $amount, $note) {
            $this->ensureEnough($domain, round($amount, 2));

            return $this->line($domain, $actor, 'out', $amount, CashOnHandMovement::OWNER_USE, $note);
        });
    }

    /** A starting balance (cash the owner already held) or a correction, with the reason. */
    public function adjust(Domain $domain, User $actor, string $direction, float $amount, string $note): CashOnHandMovement
    {
        $this->ensureOwner($actor);

        return DB::transaction(function () use ($domain, $actor, $direction, $amount, $note) {
            if ($direction === 'out') {
                $this->ensureEnough($domain, round($amount, 2));
            }

            return $this->line($domain, $actor, $direction, $amount, CashOnHandMovement::ADJUSTMENT, $note);
        });
    }

    /** The store's day was cashed out at end of day (that cash already left its drawer). */
    public static function dayCashedOut(string $domainSlug, int $locationId, string $businessDate): bool
    {
        return WalletCashMovement::query()->forWalletContext($domainSlug, $locationId)
            ->whereDate('movement_date', $businessDate)
            ->where('notes', WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT)
            ->exists();
    }

    private function line(Domain $domain, User $actor, string $direction, float $amount, string $kind, ?string $note): CashOnHandMovement
    {
        return CashOnHandMovement::query()->create([
            'domain' => $domain->name_slug,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'kind' => $kind,
            'movement_date' => now()->toDateString(),
            'notes' => filled($note) ? Str::limit(trim($note), 2000) : null,
            'user_id' => $actor->id,
        ]);
    }

    /**
     * The store's side. It belongs to the store's day, not to a shift the owner may have open there
     * (that would change their own count), so WalletCashMovement's shift stamp is skipped.
     */
    private function storeLine(Domain $domain, InventoryLocation $store, ?PaymentCardType $channel, string $direction, float $amount, string $kind, string $group, string $notes, string $date, User $actor): void
    {
        WalletCashMovement::withoutEvents(fn () => WalletCashMovement::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $store->id,
            'payment_card_type_id' => $channel?->id,
            'direction' => $direction,
            'amount' => $amount,
            'kind' => $kind,
            'transfer_group' => $group,
            'notes' => $notes,
            'movement_date' => $date,
            'user_id' => $actor->id,
        ]));
    }

    private function ensureEnough(Domain $domain, float $amount): void
    {
        $balance = self::balance($domain->name_slug);
        if ($amount <= 0 || $amount > $balance) {
            throw ValidationException::withMessages(['amount' => 'Cash on hand has ₱'.number_format($balance, 2).'.']);
        }
    }

    private function ensureOwner(User $actor): void
    {
        if (! FinanceAccess::canSeeAllStores($actor)) {
            throw ValidationException::withMessages(['amount' => 'Only an owner or admin moves Cash on hand.']);
        }
    }
}
