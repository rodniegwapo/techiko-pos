<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\RegisterShift;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
use App\Models\WalletShiftReopenLog;
use App\Services\ExpenseService;
use App\Support\FinanceAccess;
use App\Support\RouteFeature;
use App\Support\Wallet\CashOnHand;
use App\Support\Wallet\WalletCashBridgeExpected;
use App\Support\Wallet\WalletCashDailyExpected;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use App\Support\Wallet\WalletOpeningCash;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WalletCashMovementController extends Controller
{
    private const AUTO_COUNTED_VARIANCE_LEDGER_NOTE = 'AUTO_CC_COUNTED_VARIANCE';

    private const AUTO_ENDSHIFT_CASHOUT_LEDGER_NOTE = 'AUTO_CC_ENDSHIFT_CASHOUT';

    private function ensureDateNotClosed(string $domainSlug, int $locationId, string $dateYmd): void
    {
        WalletShiftGuard::ensureDateNotClosed($domainSlug, $locationId, $dateYmd);
    }

    public function index(Request $request, Domain $domain)
    {
        $qs = $request->getQueryString();

        return redirect()->to(
            route('domains.wallet.cash-in-out', ['domain' => $domain]).($qs ? '?'.$qs : '')
        );
    }

    public function store(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        if ($request->input('kind') === 'transfer') {
            return $this->storeTransfer($request, $domain, $location);
        }

        $kind = (string) $request->input('kind');
        // A transfer moves money into or out of a payment channel, so it names the channel and its
        // direction is the transfer's own.
        $isTransfer = in_array($kind, ['ewallet_transfer_in', 'ewallet_transfer_out'], true);

        $validated = $request->validate([
            'direction' => [
                'required', 'string', 'in:in,out',
                Rule::when($kind === 'ewallet_transfer_in', ['in:in']),
                Rule::when($kind === 'ewallet_transfer_out', ['in:out']),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'kind' => ['required', 'string', 'in:'.implode(',', WalletCashMovement::KINDS)],
            'draw_source' => [
                Rule::requiredIf((string) $request->input('kind') === 'owner_draw'),
                'nullable',
                'string',
                'in:cash_register,card_type',
            ],
            'payment_card_type_id' => [
                'nullable',
                'integer',
                'exists:payment_card_types,id',
                Rule::prohibitedIf(
                    (string) $request->input('kind') === 'owner_draw'
                    && (string) $request->input('draw_source') === 'cash_register'
                ),
                Rule::requiredIf(
                    ((string) $request->input('kind') === 'owner_draw'
                    && (string) $request->input('draw_source') === 'card_type')
                    || $isTransfer
                ),
            ],
            'movement_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'payment_card_type_id.required' => 'Choose the payment channel the money moves in or out of.',
            'direction.in' => $kind === 'ewallet_transfer_out'
                ? 'A transfer out takes money out of the channel.'
                : 'A transfer in puts money into the channel.',
        ]);
        $this->ensureDateNotClosed($domain->name_slug, (int) $location->id, (string) $validated['movement_date']);

        $paymentCardTypeId = $validated['payment_card_type_id'] ?? null;

        if ((string) ($validated['kind'] ?? '') === 'owner_draw' && (string) ($validated['draw_source'] ?? '') === 'cash_register') {
            $paymentCardTypeId = null;
        }

        if ($paymentCardTypeId !== null) {
            $type = PaymentCardType::query()->findOrFail((int) $paymentCardTypeId);
            WalletLedgerViewData::ensureCardTypeInDomainLocation($domain, $location, $type);
        }

        // The owner took cash from this drawer and put it in another store's drawer.
        $target = $this->ownerTransferTarget($request, $domain, $location, $validated, $paymentCardTypeId);
        $group = $target ? (string) Str::uuid() : null;

        DB::transaction(function () use ($domain, $location, $paymentCardTypeId, $validated, $request, $target, $group) {
            WalletCashMovement::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'payment_card_type_id' => $paymentCardTypeId,
                'direction' => $validated['direction'],
                'amount' => $validated['amount'],
                'kind' => $validated['kind'],
                'transfer_group' => $group,
                'notes' => $target ? Str::limit('To '.$target->name.($validated['notes'] ?? null ? ': '.$validated['notes'] : ''), 2000) : ($validated['notes'] ?? null),
                'movement_date' => $validated['movement_date'],
                'user_id' => $request->user()->id,
            ]);

            if ($target) {
                WalletCashMovement::query()->create([
                    'domain' => $domain->name_slug,
                    'location_id' => $target->id,
                    'payment_card_type_id' => null,
                    'direction' => 'in',
                    'amount' => $validated['amount'],
                    'kind' => WalletCashMovement::KIND_OWNER_TRANSFER_IN,
                    'transfer_group' => $group,
                    'notes' => Str::limit('From '.$location->name.($validated['notes'] ?? null ? ': '.$validated['notes'] : ''), 2000),
                    'movement_date' => $validated['movement_date'],
                    'user_id' => $request->user()->id,
                ]);
            }
        });

        return redirect()->back()->with('success', $target ? "Moved to {$target->name}'s drawer." : 'Ledger entry saved.');
    }

    /**
     * Money moved from this store's drawer or one of its channels to a drawer or channel of this
     * store, or (owners and admins) of another store: an out line and an in line with one
     * transfer_group, so both sides' balances follow and neither looks like income or spending.
     */
    private function storeTransfer(Request $request, Domain $domain, InventoryLocation $location)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'movement_date' => ['required', 'date', 'before_or_equal:today'],
            'from_channel_id' => ['nullable', 'integer'],
            'to_location_id' => ['nullable', 'integer'],
            'to_channel_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $slug = $domain->name_slug;
        $date = (string) $validated['movement_date'];

        $toLocation = $location;
        if (! empty($validated['to_location_id']) && (int) $validated['to_location_id'] !== (int) $location->id) {
            if (! FinanceAccess::canSeeAllStores($request->user())) {
                throw ValidationException::withMessages(['to_location_id' => 'Only an owner or admin can move money to another store.']);
            }
            $toLocation = InventoryLocation::query()->forDomain($slug)->active()->whereKey((int) $validated['to_location_id'])
                ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
                ->first()
                ?? throw ValidationException::withMessages(['to_location_id' => 'Pick another store of this business.']);
            self::ensureCanTransfer($location, $toLocation);
        }

        $channel = fn (?int $id, InventoryLocation $at, string $field) => $id
            ? (PaymentCardType::query()->forDomainLocation($slug, (int) $at->id)->find($id)
                ?? throw ValidationException::withMessages([$field => 'Pick one of '.$at->name.'\'s payment channels.']))
            : null;
        $from = $channel($validated['from_channel_id'] ?? null, $location, 'from_channel_id');
        $to = $channel($validated['to_channel_id'] ?? null, $toLocation, 'to_channel_id');

        if ((int) $toLocation->id === (int) $location->id && $from?->id === $to?->id) {
            throw ValidationException::withMessages(['to_channel_id' => 'Pick a different place to move the money to.']);
        }

        WalletShiftGuard::ensureDateNotClosed($slug, (int) $location->id, $date, 'movement_date');
        WalletShiftGuard::ensureDateNotClosed($slug, (int) $toLocation->id, $date, 'to_location_id');

        $sameStore = (int) $toLocation->id === (int) $location->id;
        $name = fn (?PaymentCardType $c, InventoryLocation $at) => ($c ? $c->name : 'Cash drawer').($sameStore ? '' : ' ('.$at->name.')');
        $notes = fn (string $text) => Str::limit($text.(($validated['notes'] ?? null) ? ': '.$validated['notes'] : ''), 2000);
        $group = (string) Str::uuid();

        DB::transaction(function () use ($slug, $location, $toLocation, $from, $to, $validated, $date, $group, $name, $notes, $request) {
            foreach ([
                [$location, $from, 'out', WalletCashMovement::KIND_TRANSFER_OUT, 'To '.$name($to, $toLocation)],
                [$toLocation, $to, 'in', WalletCashMovement::KIND_TRANSFER_IN, 'From '.$name($from, $location)],
            ] as [$at, $chan, $direction, $kind, $text]) {
                WalletCashMovement::query()->create([
                    'domain' => $slug,
                    'location_id' => $at->id,
                    'payment_card_type_id' => $chan?->id,
                    'direction' => $direction,
                    'amount' => $validated['amount'],
                    'kind' => $kind,
                    'transfer_group' => $group,
                    'notes' => $notes($text),
                    'movement_date' => $date,
                    'user_id' => $request->user()->id,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Moved to '.$name($to, $toLocation).'.');
    }

    /**
     * The store whose drawer an owner withdrawal goes into (to_location_id), or null when the owner
     * keeps the cash. Another store of this organization, with its day still open; only from the
     * cash drawer.
     */
    private function ownerTransferTarget(Request $request, Domain $domain, InventoryLocation $from, array $validated, ?int $paymentCardTypeId): ?InventoryLocation
    {
        $toId = $request->input('to_location_id');
        if (! $toId || $validated['kind'] !== 'owner_draw') {
            return null;
        }
        if ($paymentCardTypeId !== null) {
            throw ValidationException::withMessages(['to_location_id' => 'Only cash from the drawer can go to another store\'s drawer.']);
        }

        $target = InventoryLocation::query()->forDomain($domain->name_slug)->active()->whereKey((int) $toId)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->first();
        if (! $target || (int) $target->id === (int) $from->id) {
            throw ValidationException::withMessages(['to_location_id' => 'Pick another store of this business.']);
        }
        self::ensureCanTransfer($from, $target);
        WalletShiftGuard::ensureDateNotClosed($domain->name_slug, (int) $target->id, (string) $validated['movement_date'], 'to_location_id');

        return $target;
    }

    /** Each cashier counts their own tray when ending their shift; the store's whole drawer is a supervisor's and up. */
    private function ensureOverseesDrawer(Request $request): void
    {
        abort_unless(FinanceAccess::canOverseeDrawer($request->user()), 403, 'Supervisors and managers set the opening and count the store\'s drawer. End your shift to count yours.');
    }

    public function setOpeningCash(Request $request, Domain $domain)
    {
        $this->ensureOverseesDrawer($request);
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->ensureDateNotClosed($domain->name_slug, (int) $location->id, (string) $validated['business_date']);

        WalletOpeningCash::save(
            $domain->name_slug,
            $location,
            (string) $validated['business_date'],
            (float) $validated['opening_cash'],
            (int) $request->user()->id,
            $validated['reason'] ?? null,
        );

        return redirect()->back()->with('success', 'Opening cash saved.');
    }

    public function submitCountedCash(Request $request, Domain $domain)
    {
        $this->ensureOverseesDrawer($request);
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->ensureDateNotClosed($domain->name_slug, (int) $location->id, (string) $validated['business_date']);

        // An optional spot check: End the day still takes the final count (it starts from this one).
        DB::transaction(fn () => $this->recordCount($domain, $location, $validated, (int) $request->user()->id, WalletCashCountSubmission::SPOT_CHECK));

        return redirect()->back()->with('success', 'Counted cash saved.');
    }

    public function endShift(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'end_shift_action' => ['required', 'string', Rule::in(['cashout_now', 'save_as_opening_cash'])],
        ]);

        // A cashier ends their own shift; the store's day is a manager's to end.
        if (! FinanceAccess::canManage($request->user())) {
            throw ValidationException::withMessages(['end_shift_action' => 'A manager ends the store\'s day. End your own shift under My shift.']);
        }
        $this->ensureNoShiftOpen($domain, $location, (string) $validated['business_date']);

        $closed = $this->closeShift($domain, $location, $validated, (int) $request->user()->id);

        return redirect()->back()->with('success', $closed ? 'Shift closed.' : 'Shift is already closed.');
    }

    /**
     * End the store's day in one step, from Today's drawer: any cash expenses paid from the drawer,
     * the count, and closing. A manager's job, once every cashier has ended their own shift (a
     * cashier ends theirs with no PIN; a count that is off waits for review on Shifts & counts).
     */
    public function closeShiftWithCount(Request $request, Domain $domain, ExpenseService $expenses)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'end_shift_action' => ['required', 'string', Rule::in(['cashout_now', 'save_as_opening_cash'])],
            'expenses' => ['nullable', 'array', 'max:20'],
            'expenses.*.expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('domain', $domain->name_slug)],
            'expenses.*.amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'expenses.*.description' => ['required', 'string', 'max:255'],
        ], [
            'expenses.*.expense_category_id.required' => 'Pick what each expense was for.',
            'expenses.*.description.required' => 'Say what each expense was.',
        ]);
        $this->ensureDateNotClosed($domain->name_slug, (int) $location->id, (string) $validated['business_date']);

        $user = $request->user();
        if (! FinanceAccess::canManage($user)) {
            throw ValidationException::withMessages(['end_shift_action' => 'A manager ends the store\'s day. End your own shift under My shift.']);
        }
        $this->ensureNoShiftOpen($domain, $location, (string) $validated['business_date']);
        if (! empty($validated['expenses']) && ! RouteFeature::allows($domain, 'expenses.store')) {
            throw ValidationException::withMessages(['expenses' => 'Expenses aren\'t part of this plan.']);
        }

        DB::transaction(function () use ($validated, $domain, $location, $user, $expenses) {
            // Paid out of the drawer this shift: they lower Expected before the count is compared.
            foreach ($validated['expenses'] ?? [] as $row) {
                $expenses->create([
                    'domain' => $domain->name_slug,
                    'location_id' => $location->id,
                    'expense_category_id' => (int) $row['expense_category_id'],
                    'amount' => $row['amount'],
                    'expense_date' => $validated['business_date'],
                    'description' => $row['description'],
                    'payment_method' => 'cash_register',
                    'user_id' => $user->id,
                ]);
            }

            $this->recordCount($domain, $location, $validated, (int) $user->id, WalletCashCountSubmission::END_OF_DAY);
            $this->closeShift($domain, $location, $validated, (int) $user->id);
        });

        return redirect()->back()->with('success', 'Shift ended.');
    }

    /** The store's day ends after the cashiers' shifts: each one is counted on its own first. */
    /**
     * A store's day ends after every shift of that day has ended. A shift forgotten from an earlier
     * day doesn't hold up later days: it shows as overdue on Today's drawer, to be ended there.
     */
    private function ensureNoShiftOpen(Domain $domain, InventoryLocation $location, string $businessDate): void
    {
        $open = RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
            ->open()->whereDate('business_date', '>=', $businessDate)->with('user:id,name')->get();
        if ($open->isNotEmpty()) {
            throw ValidationException::withMessages([
                'end_shift_action' => 'Still on shift: '.$open->map(fn ($s) => $s->user?->name ?? 'a cashier')->join(', ').'. Each ends their shift first.',
            ]);
        }
    }

    /**
     * Saves a count of the drawer for a business date: the count, its difference from Expected as
     * a ledger line, and a row in the count history (with the manager who approved it, if any).
     *
     * @param  array{business_date: string, counted_cash: float|string, notes?: ?string}  $validated
     */
    private function recordCount(Domain $domain, InventoryLocation $location, array $validated, int $userId, string $kind, ?int $approvedBy = null): void
    {
        $countedAt = now();

        $recon = WalletCashReconciliation::query()->updateOrCreate(
            [
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'business_date' => $validated['business_date'],
            ],
            [
                'counted_cash' => round((float) $validated['counted_cash'], 2),
                'notes' => $validated['notes'] ?? null,
                'counted_by' => $userId,
                'counted_at' => $countedAt,
            ]
        );

        // A recount replaces this round's count difference. A day ended and reopened keeps the
        // differences of its earlier rounds: that money was short or over before the cash moved on.
        WalletCashMovement::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('movement_date', $validated['business_date'])
            ->where('notes', self::AUTO_COUNTED_VARIANCE_LEDGER_NOTE)
            ->when($recon->opening_basis_at, fn ($q, $basis) => $q->where('created_at', '>', $basis))
            ->delete();

        $dailyExpected = WalletCashDailyExpected::compute(
            $domain->name_slug,
            (int) $location->id,
            (string) $validated['business_date'],
            $recon
        );
        $expectedCash = $dailyExpected['expected_cash'];
        $countedCash = round((float) $recon->counted_cash, 2);
        $variance = round($countedCash - $expectedCash, 2);

        $bridge = WalletCashBridgeExpected::compute(
            $domain->name_slug,
            (int) $location->id,
            (string) $validated['business_date'],
            $countedCash
        );
        $bridgeVariance = $bridge['bridge_variance'];
        $bridgeExpected = $bridge['bridge_expected_cash'];
        $skipDailyVarianceLedger = $bridgeExpected !== null
            && $bridgeVariance !== null
            && abs($bridgeVariance) <= 0.01
            && abs($variance) > 0.01;

        if (abs($variance) > 0 && ! $skipDailyVarianceLedger) {
            WalletCashMovement::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'payment_card_type_id' => null,
                'direction' => $variance > 0 ? 'in' : 'out',
                'amount' => abs($variance),
                'kind' => 'adjustment',
                'notes' => self::AUTO_COUNTED_VARIANCE_LEDGER_NOTE,
                'movement_date' => $validated['business_date'],
                'user_id' => $userId,
            ]);
        }

        WalletCashCountSubmission::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
            'reconciliation_id' => $recon->id,
            'business_date' => $validated['business_date'],
            'counted_cash' => $countedCash,
            'expected_cash_snapshot' => $expectedCash,
            'variance_snapshot' => $variance,
            'notes' => $recon->notes,
            'counted_by' => $userId,
            'approved_by' => $approvedBy,
            'counted_at' => $countedAt,
            'kind' => $kind,
        ]);
    }

    /**
     * Ends the shift of a counted drawer: the cash is taken out, or left as the next opening.
     *
     * @param  array{business_date: string, end_shift_action: string}  $validated
     * @return bool False when it was already closed.
     */
    /** Money moves between two stores only when both have "Can transfer money" on (Locations). */
    private static function ensureCanTransfer(InventoryLocation $from, InventoryLocation $to): void
    {
        if (! $from->allows_money_transfer || ! $to->allows_money_transfer) {
            throw ValidationException::withMessages(['to_location_id' => 'Turn on Can transfer money for both stores first (Inventory → Locations).']);
        }
    }

    private function closeShift(Domain $domain, InventoryLocation $location, array $validated, int $userId): bool
    {
        $recon = WalletCashReconciliation::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('business_date', $validated['business_date'])
            ->first();

        if (! $recon || $recon->counted_cash === null) {
            throw ValidationException::withMessages([
                'counted_cash' => 'End Shift requires counted cash first. No automatic expected-to-opening fallback.',
            ]);
        }

        if ($recon->is_closed) {
            return false;
        }

        $action = (string) $validated['end_shift_action'];
        $countedCash = round((float) $recon->counted_cash, 2);

        if ($action === 'cashout_now') {
            if ($countedCash <= 0) {
                throw ValidationException::withMessages([
                    'counted_cash' => 'Cash out requires counted cash greater than zero.',
                ]);
            }

            $recon->opening_cash = 0;
            $recon->opening_source = 'manual';
            $recon->opening_source_date = null;
            $recon->opening_basis_at = now();
            // No count until someone counts again: a reopened day can't be ended on the old one.
            $recon->counted_cash = null;
            $recon->counted_by = null;
            $recon->counted_at = null;
            $recon->notes = null;
            $recon->save();

            // The day's cash goes to the owner: it joins Cash on hand, still the business's money.
            $group = (string) Str::uuid();
            WalletCashMovement::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'payment_card_type_id' => null,
                'direction' => 'out',
                'amount' => $countedCash,
                'kind' => WalletCashMovement::KIND_TO_CASH_ON_HAND,
                'transfer_group' => $group,
                'notes' => self::AUTO_ENDSHIFT_CASHOUT_LEDGER_NOTE,
                'movement_date' => $validated['business_date'],
                'user_id' => $userId,
            ]);
            app(CashOnHand::class)->recordEndOfDayCashOut($domain, $location, (string) $validated['business_date'], $countedCash, $userId, $group);
        }

        if ($action === 'save_as_opening_cash') {
            $oldOpening = $recon->opening_cash !== null ? (float) $recon->opening_cash : null;
            $recon->opening_cash = $countedCash;
            $recon->opening_source = 'manual';
            $recon->opening_source_date = null;
            $recon->opening_basis_at = now();
            $recon->counted_cash = null;
            $recon->counted_by = null;
            $recon->counted_at = null;
            $recon->notes = null;
            $recon->save();

            WalletCashOpeningAudit::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'business_date' => $validated['business_date'],
                'reconciliation_id' => $recon->id,
                'old_opening_cash' => $oldOpening,
                'new_opening_cash' => $countedCash,
                'delta_amount' => round($countedCash - (float) ($oldOpening ?? 0), 2),
                'changed_by' => $userId,
                'changed_at' => now(),
                'reason' => 'Set from End Shift action: save_as_opening_cash.',
            ]);

            WalletOpeningCash::syncLedgerLine($domain->name_slug, $location, $validated['business_date'], $countedCash, (int) $userId);
        }

        $recon->is_closed = true;
        $recon->closed_at = now();
        $recon->closed_by = $userId;
        $recon->save();

        return true;
    }

    public function reopenShift(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Say why the shift is reopened.',
        ]);

        $recon = WalletCashReconciliation::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('business_date', $validated['business_date'])
            ->firstOrFail();

        if (! $recon->is_closed) {
            return redirect()->back()->with('success', 'Shift is already open.');
        }

        // Anyone with the reopen permission may do it (the route middleware checks that); the log keeps who and why.
        DB::transaction(function () use ($recon, $domain, $location, $request, $validated) {
            WalletShiftReopenLog::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'reconciliation_id' => $recon->id,
                'business_date' => $validated['business_date'],
                'reopened_by' => $request->user()->id,
                'closed_by' => $recon->closed_by,
                'closed_at' => $recon->closed_at,
                'reason' => trim($validated['reason']),
                // What the day showed when it was ended, kept with the reopen.
                'before' => [
                    'opening_cash' => (float) $recon->opening_cash,
                    'counted_cash' => $recon->counted_cash !== null ? (float) $recon->counted_cash : null,
                    // Cash taken out of the drawer when the day was ended (so far, every round).
                    'cashed_out' => round((float) WalletCashMovement::query()
                        ->forWalletContext($domain->name_slug, $location->id)
                        ->whereDate('movement_date', $validated['business_date'])
                        ->where('notes', self::AUTO_ENDSHIFT_CASHOUT_LEDGER_NOTE)
                        ->sum('amount'), 2),
                ],
            ]);

            $recon->is_closed = false;
            $recon->reopened_at = now();
            $recon->reopened_by = $request->user()->id;
            $recon->closed_at = null;
            $recon->closed_by = null;
            $recon->save();
        });

        return redirect()->back()->with('success', 'Shift reopened.');
    }
}
