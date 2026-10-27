<?php

namespace App\Support\Wallet;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Models\WalletShiftReopenLog;
use App\Services\ExpenseService;
use App\Support\FinanceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A cashier's own shift: started with the cash they begin with, ended with their count. A count
 * off from Expected goes to a manager (needs_review) instead of stopping anyone: other cashiers
 * keep selling, and the same cashier may start a new shift if the store allows it. Nothing is
 * overwritten without a trace: every count is a submission row, every reopen a log row with what
 * the shift showed before.
 */
class RegisterShiftService
{
    /** A count within this of Expected matches. */
    private const TOLERANCE = 0.01;

    public function __construct(private ExpenseService $expenses) {}

    /**
     * What should be in this cashier's drawer: their opening, their cash sales, and the cash lines
     * added during their shift (book-only AUTO_CC_* lines aside, like the store's own Expected).
     *
     * @return array{opening_cash: float, paid_cash_sales: float, manual_in: float, manual_out: float, expected_cash: float}
     */
    public function figures(RegisterShift $shift): array
    {
        $sales = Sale::query()->where('register_shift_id', $shift->id)->where('payment_status', 'paid');
        $lines = WalletCashMovement::query()
            ->where('register_shift_id', $shift->id)
            ->inDrawer()
            ->whereNotIn('kind', [WalletCashMovement::KIND_SALE_PAYMENT, WalletCashMovement::KIND_SALE_VOID])
            ->forManualLedgerRunningBalance();

        $opening = round((float) $shift->opening_cash, 2);
        $cashSales = SalePayment::totalFor($sales, 'cash');
        $in = round((float) (clone $lines)->where('direction', 'in')->sum('amount'), 2);
        $out = round((float) (clone $lines)->where('direction', 'out')->sum('amount'), 2);

        return [
            'opening_cash' => $opening,
            'paid_cash_sales' => $cashSales,
            'manual_in' => $in,
            'manual_out' => $out,
            'expected_cash' => round($opening + $cashSales + $in - $out, 2),
        ];
    }

    public function start(Domain $domain, InventoryLocation $location, User $user, float $openingCash, ?string $note = null): RegisterShift
    {
        $today = now()->toDateString();
        if (WalletShiftGuard::isClosed($domain->name_slug, (int) $location->id, $today)) {
            throw ValidationException::withMessages(['shift' => 'The store\'s day was ended. A manager reopens it before new shifts start.']);
        }
        if (RegisterShift::openFor($domain->name_slug, (int) $location->id, (int) $user->id)) {
            throw ValidationException::withMessages(['shift' => 'You already have a shift open at this store.']);
        }
        if (! $domain->allowsNewShiftWhileReview() && $this->hasShiftInReview($domain->name_slug, (int) $location->id, (int) $user->id)) {
            throw ValidationException::withMessages(['shift' => 'Your last shift is waiting for a manager to review its count.']);
        }

        return DB::transaction(function () use ($domain, $location, $user, $openingCash, $note, $today) {
            // The first shift of the day opens the store's day with the cash it starts with.
            $storeDayExists = WalletCashReconciliation::query()
                ->forWalletContext($domain->name_slug, (int) $location->id)
                ->whereDate('business_date', $today)
                ->exists();
            if (! $storeDayExists) {
                WalletOpeningCash::save($domain->name_slug, $location, $today, $openingCash, (int) $user->id, $note ?: 'Opened by '.$user->name.'\'s shift');
            }

            return RegisterShift::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'business_date' => $today,
                'user_id' => $user->id,
                'opening_cash' => round($openingCash, 2),
                'opened_at' => now(),
                'notes' => $note,
                'status' => RegisterShift::OPEN,
            ]);
        });
    }

    /**
     * Ends a shift with its count, and any cash expenses paid from the drawer during it. No PIN:
     * a count that is off goes to a manager to review.
     *
     * @param  array{counted_cash: float|string, notes?: ?string, expenses?: list<array{expense_category_id: int, amount: float|string, description: string}>}  $data
     */
    public function end(RegisterShift $shift, User $actor, array $data): RegisterShift
    {
        $this->ensureOwnerOrManager($shift, $actor);
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This shift was already ended.']);
        }

        return DB::transaction(function () use ($shift, $actor, $data) {
            foreach ($data['expenses'] ?? [] as $row) {
                $this->expenses->create([
                    'domain' => $shift->domain,
                    'location_id' => $shift->location_id,
                    'expense_category_id' => (int) $row['expense_category_id'],
                    'amount' => $row['amount'],
                    'expense_date' => $shift->business_date->toDateString(),
                    'description' => $row['description'],
                    'payment_method' => 'cash_register',
                    // Its drawer line joins this shift (WalletCashMovement stamps the cashier's open shift).
                    'user_id' => $shift->user_id,
                ]);
            }

            $expected = $this->figures($shift)['expected_cash'];
            $counted = round((float) $data['counted_cash'], 2);
            $variance = round($counted - $expected, 2);
            $countedAt = now();

            $shift->update([
                'counted_cash' => $counted,
                'expected_cash' => $expected,
                'variance' => $variance,
                'notes' => $data['notes'] ?? $shift->notes,
                'closed_at' => $countedAt,
                'status' => abs($variance) <= self::TOLERANCE ? RegisterShift::CLOSED : RegisterShift::NEEDS_REVIEW,
            ]);

            WalletCashCountSubmission::query()->create([
                'domain' => $shift->domain,
                'location_id' => $shift->location_id,
                'reconciliation_id' => WalletCashReconciliation::query()
                    ->forWalletContext($shift->domain, (int) $shift->location_id)
                    ->whereDate('business_date', $shift->business_date)
                    ->value('id'),
                'register_shift_id' => $shift->id,
                'business_date' => $shift->business_date->toDateString(),
                'counted_cash' => $counted,
                'expected_cash_snapshot' => $expected,
                'variance_snapshot' => $variance,
                'notes' => $data['notes'] ?? null,
                'counted_by' => $actor->id,
                'counted_at' => $countedAt,
            ]);

            return $shift->refresh();
        });
    }

    /**
     * Opens an ended shift again, with a reason. The log keeps what it showed (its count is in the
     * count history too), so ending it again adds a new count rather than replacing the old one.
     */
    public function reopen(RegisterShift $shift, User $actor, string $reason): RegisterShift
    {
        $this->ensureOwnerOrManager($shift, $actor);
        if ($shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This shift is already open.']);
        }
        if ($shift->status === RegisterShift::APPROVED) {
            throw ValidationException::withMessages(['shift' => 'A manager already approved this shift\'s count; record a correction in Cash in & out instead.']);
        }
        if (RegisterShift::openFor($shift->domain, (int) $shift->location_id, (int) $shift->user_id)) {
            throw ValidationException::withMessages(['shift' => 'End the shift that is open now before reopening this one.']);
        }
        if (WalletShiftGuard::isClosed($shift->domain, (int) $shift->location_id, $shift->business_date->toDateString())) {
            throw ValidationException::withMessages(['shift' => 'The store\'s day was ended. A manager reopens it first.']);
        }

        return DB::transaction(function () use ($shift, $actor, $reason) {
            WalletShiftReopenLog::query()->create([
                'domain' => $shift->domain,
                'location_id' => $shift->location_id,
                'register_shift_id' => $shift->id,
                'business_date' => $shift->business_date->toDateString(),
                'reopened_by' => $actor->id,
                'closed_by' => $shift->user_id,
                'closed_at' => $shift->closed_at,
                'reason' => trim($reason),
                'before' => [
                    'status' => $shift->status,
                    'counted_cash' => $shift->counted_cash,
                    'expected_cash' => $shift->expected_cash,
                    'variance' => $shift->variance,
                ],
            ]);

            $shift->update([
                'status' => RegisterShift::OPEN,
                'counted_cash' => null,
                'expected_cash' => null,
                'variance' => null,
                'closed_at' => null,
            ]);

            return $shift->refresh();
        });
    }

    /** A manager signs off an ended shift's count (and the count row it came from). */
    public function approve(RegisterShift $shift, User $actor, ?string $note = null): RegisterShift
    {
        if (! in_array($shift->status, [RegisterShift::CLOSED, RegisterShift::NEEDS_REVIEW], true)) {
            throw ValidationException::withMessages(['shift' => $shift->isOpen() ? 'The shift is still open.' : 'This shift was already approved.']);
        }
        // Someone else checks a manager's own count; admins and owners may sign off their own.
        if ((int) $shift->user_id === (int) $actor->id && ! $actor->isSuperUser() && ! FinanceAccess::canSeeAllStores($actor)) {
            throw ValidationException::withMessages(['shift' => 'Another manager approves your own shift.']);
        }

        return DB::transaction(function () use ($shift, $actor, $note) {
            $now = now();
            $shift->update([
                'status' => RegisterShift::APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $now,
                'review_note' => filled($note) ? trim($note) : null,
            ]);
            $shift->countSubmissions()->latest('id')->first()?->update(['approved_by' => $actor->id, 'approved_at' => $now]);

            return $shift->refresh();
        });
    }

    public function hasShiftInReview(string $domainSlug, int $locationId, int $userId): bool
    {
        return RegisterShift::query()->forWalletContext($domainSlug, $locationId)
            ->where('user_id', $userId)->where('status', RegisterShift::NEEDS_REVIEW)->exists();
    }

    private function ensureOwnerOrManager(RegisterShift $shift, User $actor): void
    {
        if ((int) $shift->user_id !== (int) $actor->id && ! FinanceAccess::canManage($actor)) {
            throw ValidationException::withMessages(['shift' => 'Only the cashier or a manager can change this shift.']);
        }
    }
}
