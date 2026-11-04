<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\RegisterShift;
use App\Models\WalletCashMovement;
use App\Services\TimeclockService;
use App\Support\FinanceAccess;
use App\Support\RouteFeature;
use App\Support\Wallet\MoneyByUser;
use App\Support\Wallet\RegisterShiftService;
use App\Support\Wallet\WalletLocationResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A cashier's own shift: start it, end it with a count (no manager's PIN; a count that is off waits
 * for review), reopen it with a reason. Managers approve counts on Today's drawer or Shifts & counts and see who holds
 * what (View details).
 */
class RegisterShiftController extends Controller
{
    public function __construct(
        private RegisterShiftService $shifts,
        private TimeclockService $timeclock,
    ) {}

    public function start(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // "Clock me in too": their own PIN, checked before anything is saved.
            'clock_in_pin' => ['nullable', 'string', 'max:20'],
        ]);
        $user = $request->user();

        // Already on the clock: the PIN isn't needed, so it's ignored.
        $clockIn = filled($validated['clock_in_pin'] ?? null) && ! $this->timeclock->statusFor($domain, $user)['on_the_clock'];
        if ($clockIn) {
            $this->timeclock->verifyOwnPin($domain, $user, $validated['clock_in_pin'], $this->deviceKey($request), 'clock_in_pin');
        }

        $punch = DB::transaction(function () use ($domain, $location, $user, $validated, $clockIn) {
            $this->shifts->start($domain, $location, $user, (float) $validated['opening_cash'], $validated['notes'] ?? null);

            return $clockIn ? $this->timeclock->clockIn($domain, $location, $user) : null;
        });

        return redirect()->back()->with('success', $punch
            ? "Drawer opened. You're clocked in at {$this->time($domain, $punch['at'])}."
            : 'Drawer opened.');
    }

    public function end(Request $request, Domain $domain, RegisterShift $registerShift)
    {
        $this->ensureInDomain($request, $domain, $registerShift);
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expenses' => ['nullable', 'array', 'max:20'],
            'expenses.*.expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('domain', $domain->name_slug)],
            'expenses.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'expenses.*.description' => ['required', 'string', 'max:255'],
            // "Clock me out too": their own PIN, checked before anything is saved.
            'clock_out_pin' => ['nullable', 'string', 'max:20'],
        ], [
            'expenses.*.expense_category_id.required' => 'Pick what each expense was for.',
            'expenses.*.description.required' => 'Say what each expense was.',
        ]);
        if (! empty($validated['expenses']) && ! RouteFeature::allows($domain, 'expenses.store')) {
            throw ValidationException::withMessages(['expenses' => 'Expenses aren\'t part of this plan.']);
        }

        $user = $request->user();
        $pin = $validated['clock_out_pin'] ?? null;
        unset($validated['clock_out_pin']);

        // "Clock me out too" is only for your own drawer: a manager closing someone else's never clocks them out.
        $clockOut = filled($pin) && (int) $registerShift->user_id === (int) $user->id
            && $this->timeclock->statusFor($domain, $user)['on_the_clock'];
        if ($clockOut) {
            $this->timeclock->verifyOwnPin($domain, $user, $pin, $this->deviceKey($request), 'clock_out_pin');
        }

        [$shift, $punch] = DB::transaction(fn () => [
            $this->shifts->end($registerShift, $user, $validated),
            $clockOut ? $this->timeclock->clockOut($domain, $user) : null,
        ]);

        $message = $shift->status === RegisterShift::NEEDS_REVIEW
            ? 'Drawer closed. The count is off from Expected, so a manager will review it.'
            : 'Drawer closed.';

        return redirect()->back()->with('success', $punch
            ? "{$message} You're clocked out at {$this->time($domain, $punch['at'])}."
            : $message);
    }

    /** The same device key the Clock in / out keypad uses, so wrong PINs share one limit. */
    private function deviceKey(Request $request): string
    {
        return $request->user()->id.'|'.$request->ip();
    }

    private function time(Domain $domain, string $at): string
    {
        return Carbon::parse($at)->timezone($domain->timezone ?: 'Asia/Manila')->format('g:i A');
    }

    public function spotCheck(Request $request, Domain $domain, RegisterShift $registerShift)
    {
        $this->ensureInDomain($request, $domain, $registerShift);
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->shifts->spotCheck($registerShift, $request->user(), (float) $validated['counted_cash'], $validated['notes'] ?? null);

        return redirect()->back()->with('success', 'Spot check saved.');
    }

    public function reopen(Request $request, Domain $domain, RegisterShift $registerShift)
    {
        $this->ensureInDomain($request, $domain, $registerShift);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Say why the shift is reopened.',
        ]);

        $this->shifts->reopen($registerShift, $request->user(), $validated['reason']);

        return redirect()->back()->with('success', 'Shift reopened.');
    }

    public function approve(Request $request, Domain $domain, RegisterShift $registerShift)
    {
        $this->ensureInDomain($request, $domain, $registerShift);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->shifts->approve($registerShift, $request->user(), $validated['note'] ?? null);

        return redirect()->back()->with('success', 'Count approved.');
    }

    /** One shift in full: its figures, sales, cash lines, every count, and every reopen. */
    public function show(Request $request, Domain $domain, RegisterShift $registerShift)
    {
        $this->ensureInDomain($request, $domain, $registerShift);
        // A supervisor opens her team's shifts only.
        abort_unless(RegisterShift::query()->whereKey($registerShift->id)->visibleTo($request->user())->exists(), 404);
        $registerShift->load(['user:id,name', 'reviewedBy:id,name', 'location:id,name']);

        return response()->json([
            'shift' => $registerShift->toRow(),
            'figures' => $this->shifts->figures($registerShift),
            'sales' => $registerShift->sales()
                ->with('paymentCardType:id,name')
                ->orderBy('transaction_date')
                ->get(['id', 'invoice_number', 'transaction_date', 'payment_method', 'payment_card_type_id', 'grand_total', 'payment_status'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'invoice_number' => $s->invoice_number,
                    'at' => $s->transaction_date ? Carbon::parse($s->transaction_date)->toIso8601String() : null,
                    'method' => $s->payment_method,
                    'channel' => $s->paymentCardType?->name,
                    'total' => (float) $s->grand_total,
                    'status' => $s->payment_status,
                ]),
            'cash_lines' => WalletCashMovement::query()
                ->where('register_shift_id', $registerShift->id)
                ->whereNotIn('kind', [WalletCashMovement::KIND_SALE_PAYMENT, WalletCashMovement::KIND_SALE_VOID])
                ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', 'AUTO_CC_%'))
                ->orderBy('created_at')
                ->get(['id', 'kind', 'direction', 'amount', 'notes', 'payment_card_type_id', 'created_at']),
            'counts' => $registerShift->countSubmissions()->with(['countedBy:id,name'])->orderBy('counted_at')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'counted_cash' => $c->counted_cash,
                    'expected_cash' => $c->expected_cash_snapshot,
                    'variance' => $c->variance_snapshot,
                    'notes' => $c->notes,
                    // spot_check during the shift, or shift: the count it ended with.
                    'kind' => $c->kind,
                    'counted_by' => $c->countedBy?->name,
                    'counted_at' => $c->counted_at?->toIso8601String(),
                    'approved_at' => $c->approved_at?->toIso8601String(),
                ]),
            'reopens' => $registerShift->reopenLogs()->with('reopenedBy:id,name')->orderBy('created_at')->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'by' => $l->reopenedBy?->name,
                    'at' => $l->created_at?->toIso8601String(),
                    'reason' => $l->reason,
                    'before' => $l->before,
                ]),
        ]);
    }

    /** Who took in what today (or on business_date), by where the money went. */
    public function moneyByUser(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate(['business_date' => ['sometimes', 'date', 'before_or_equal:today']]);
        $date = (string) ($validated['business_date'] ?? now()->toDateString());

        return response()->json([
            'business_date' => $date,
            'users' => MoneyByUser::build($domain->name_slug, (int) $location->id, $date),
        ]);
    }

    /** The shift is this organization's, and a manager tied to one store sees only that store's. */
    private function ensureInDomain(Request $request, Domain $domain, RegisterShift $shift): void
    {
        abort_unless($shift->domain === $domain->name_slug, 404);

        $user = $request->user();
        $onlyStore = FinanceAccess::restrictedLocationId($user);
        if ((int) $shift->user_id !== (int) $user->id && $onlyStore !== null && $onlyStore !== (int) $shift->location_id) {
            abort(403);
        }
    }
}
