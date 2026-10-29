<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\RegisterShift;
use App\Support\FinanceAccess;
use App\Support\RouteFeature;
use App\Support\RoutePermission;
use App\Support\Wallet\AllStoresMoney;
use App\Support\Wallet\CashControlSnapshot;
use App\Support\Wallet\CashOnHand;
use App\Support\Wallet\ChannelBalances;
use App\Support\Wallet\MoneyTimeline;
use App\Support\Wallet\RegisterShiftService;
use App\Support\Wallet\StoresCash;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Cash & wallet, one job per page:
 *  - Today's drawer: opening → cash sales → cash in/out → expected; count and end the shift.
 *  - Cash in & out: every line in and out of the drawer (and payment channels), adding cash by hand.
 *  - Where money is: what the drawer and each payment channel hold; managing the channels.
 *  - Drawer history: counts, opening changes, the check since the last count, closed shifts.
 * The numbers come from the same builders as before; only the pages changed.
 */
class WalletPagesController extends Controller
{
    public function drawer(Request $request, Domain $domain)
    {
        // A warehouse has no drawer of its own: it sees what the stores have already closed.
        $location = WalletLocationResolver::resolve($request, $domain);
        if ($location->isWarehouse()) {
            return Inertia::render('Wallet/StoresCash', [
                'storesCash' => StoresCash::build($request, $domain),
                // Where every store's money is right now (owners and admins).
                'allStores' => FinanceAccess::canSeeAllStores($request->user()) ? AllStoresMoney::build($domain, (int) $request->user()->id) : null,
                // Someone who runs a till here is told to switch to a store for their shift.
                'canStartShift' => RoutePermission::allows($request->user(), 'register-shifts.start'),
            ]);
        }

        $cashControl = CashControlSnapshot::build($domain, $location, $this->businessDate($request), (int) $request->user()->id);
        // A cashier counts and sees what should be in the drawer, not whether their count came out
        // short or over: that, and the count history, stay with managers.
        $canSeeCountResult = FinanceAccess::canManage($request->user());
        if (! $canSeeCountResult) {
            $cashControl = [
                ...collect($cashControl)->reject(fn ($v, $key) => str_starts_with($key, 'bridge_'))->all(),
                'variance' => null,
                'count_submission_history' => [],
            ];
        }

        $user = $request->user();
        $shifts = app(RegisterShiftService::class);
        $mine = RegisterShift::openFor($domain->name_slug, (int) $location->id, (int) $user->id)
            ?? RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
                ->where('user_id', $user->id)->whereDate('business_date', now()->toDateString())
                ->latest('id')->first();

        return Inertia::render('Wallet/Drawer', [
            ...$this->pageProps($request, $domain, $location),
            'cashControl' => $cashControl,
            'canSeeCountResult' => $canSeeCountResult,
            // The user's own shift: open now, or the last one they ended today.
            'myShift' => $mine ? [
                ...$mine->load('reviewedBy:id,name')->toRow(),
                'figures' => $mine->isOpen() ? $shifts->figures($mine) : null,
                'can_reopen' => ! $mine->isOpen() && $mine->status !== RegisterShift::APPROVED
                    && RoutePermission::allows($user, 'register-shifts.reopen'),
            ] : null,
            // What the user's drawer still holds from their last shift today, to start the next one with.
            'nextShiftOpening' => $mine?->isOpen() ? null : $shifts->carriedOpening($domain->name_slug, (int) $location->id, (int) $user->id),
            'canStartShift' => RoutePermission::allows($user, 'register-shifts.start'),
            // Ending the store's day stays with managers, after every cashier has ended their shift.
            'openShiftsAtStore' => RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
                ->open()->with('user:id,name')->get()->map(fn (RegisterShift $s) => ['id' => $s->id, 'user' => $s->user?->name])->values(),
            'canSeeMoneyByUser' => RoutePermission::allows($user, 'register-shifts.money-by-user'),
            // Cash expenses paid from the drawer can be added when ending the shift.
            'expenseCategories' => RouteFeature::allows($domain, 'expenses.store')
                ? ExpenseCategory::query()->forDomain($domain->name_slug)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function cashInOut(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $ledger = WalletLedgerViewData::buildPaginated($request, $domain, $location);
        // Other stores money can move to: both this one and they have "Can transfer money" on.
        $otherStores = $location->allows_money_transfer
            ? InventoryLocation::query()->forDomain($domain->name_slug)->active()
                ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
                ->where('allows_money_transfer', true)
                ->whereKeyNot($location->id)->orderBy('name')->get(['id', 'name'])
            : collect();

        return Inertia::render('Wallet/CashInOut', [
            ...$this->pageProps($request, $domain, $location),
            'ledger' => $ledger,
            'channels' => $ledger['railCardTypes'],
            // Where an owner withdrawal can go instead of the owner's pocket: the other stores.
            'otherStores' => $otherStores,
            // Where a transfer can go besides this store: other stores' drawers and channels (owners and admins).
            'transferStores' => FinanceAccess::canSeeAllStores($request->user())
                ? $otherStores->map(fn (InventoryLocation $store) => [
                    'id' => (int) $store->id,
                    'name' => (string) $store->name,
                    'channels' => PaymentCardType::query()->forDomainLocation($domain->name_slug, (int) $store->id)->active()
                        ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
                ])->values()
                : [],
            'todayIsClosed' => WalletShiftGuard::isClosed($domain->name_slug, (int) $location->id, now()->toDateString()),
            // What there is to take out, shown under the amount: the drawer's Expected today and each
            // channel's balance, the same figures as Where money is (for those who may see them).
            'available' => RoutePermission::allows($request->user(), 'wallet-cash-ledger.index') ? [
                'drawer' => CashControlSnapshot::build($domain, $location, now()->toDateString(), (int) $request->user()->id)['expected_cash'],
                'channels' => ChannelBalances::forLocation($domain->name_slug, (int) $location->id)->pluck('balance', 'id'),
            ] : null,
        ]);
    }

    public function balances(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $user = $request->user();
        $props = $this->pageProps($request, $domain, $location);

        $canSeeAllStores = FinanceAccess::canSeeAllStores($user);
        $tab = match (true) {
            $request->query('tab') === 'all' && $props['canViewAllMoney'] => 'all',
            $request->query('tab') === 'stores' && $canSeeAllStores => 'stores',
            default => 'balances',
        };
        $canSeeBalances = RoutePermission::allows($user, 'wallet-cash-ledger.index');

        return Inertia::render('Wallet/Balances', [
            ...$props,
            'tab' => $tab,
            'canSeeBalances' => $canSeeBalances,
            // The drawer: today's Expected, the same figure as Today's drawer.
            'drawerExpected' => $canSeeBalances
                ? CashControlSnapshot::build($domain, $location, now()->toDateString(), (int) $user->id)['expected_cash']
                : null,
            'channelBalances' => $canSeeBalances ? ChannelBalances::forLocation($domain->name_slug, (int) $location->id) : [],
            'todayIsClosed' => WalletShiftGuard::isClosed($domain->name_slug, (int) $location->id, now()->toDateString()),
            'allMoney' => $tab === 'all' ? MoneyTimeline::build($request, $domain, $location) : null,
            // Every store's drawer and channels side by side: for owners and admins.
            'canSeeAllStores' => $canSeeAllStores,
            'allStores' => $tab === 'stores' ? AllStoresMoney::build($domain, (int) $user->id) : null,
        ]);
    }

    public function history(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        return Inertia::render('Wallet/History', [
            ...$this->pageProps($request, $domain, $location),
            'cashControl' => CashControlSnapshot::build($domain, $location, $this->businessDate($request), (int) $request->user()->id),
            'closedShifts' => StoresCash::build($request, $domain, (int) $location->id),
        ]);
    }

    /**
     * Counted money: every cashier's ended shift with its count and difference, the ones waiting
     * for review first, for a manager to approve.
     */
    public function counts(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['needs_review', 'closed', 'approved', 'open', 'all'])],
        ]);
        $status = $validated['status'] ?? RegisterShift::NEEDS_REVIEW;

        $base = RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id);
        $shifts = (clone $base)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['user:id,name', 'reviewedBy:id,name', 'collectedBy:id,name'])
            ->withCount('reopenLogs')
            ->orderByDesc('business_date')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (RegisterShift $s) => [
                ...$s->toRow(),
                'reopen_count' => (int) $s->reopen_logs_count,
                // Approved and not collected yet, and its day wasn't cashed out at end of day.
                'collectable' => $s->status === RegisterShift::APPROVED && $s->collected_at === null && (float) $s->counted_cash > 0
                    && ! CashOnHand::dayCashedOut($domain->name_slug, (int) $s->location_id, $s->business_date->toDateString()),
            ]);

        return Inertia::render('Wallet/Counts', [
            ...$this->pageProps($request, $domain, $location),
            'status' => $status,
            'shifts' => $shifts,
            'statusCounts' => (clone $base)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'canApprove' => RoutePermission::allows($request->user(), 'register-shifts.approve'),
            // Owners and admins collect approved shifts' cash into Cash on hand.
            'canCollect' => RoutePermission::allows($request->user(), 'cash-on-hand.collect'),
            'currentUserId' => (int) $request->user()->id,
        ]);
    }

    /**
     * What every wallet page needs: the store, and which of the pages and actions this user has.
     *
     * @return array<string, mixed>
     */
    private function pageProps(Request $request, Domain $domain, InventoryLocation $location): array
    {
        $user = $request->user();

        return [
            'activeLocation' => ['id' => $location->id, 'name' => $location->name],
            // Every peso in and out, bank and e-wallet payments included: admins, and managers for their own store.
            'canViewAllMoney' => FinanceAccess::canManage($user)
                && (FinanceAccess::canSeeAllStores($user) || FinanceAccess::restrictedLocationId($user) === (int) $location->id),
        ];
    }

    private function businessDate(Request $request): string
    {
        $validated = $request->validate([
            'business_date' => ['sometimes', 'date', 'before_or_equal:today'],
        ]);

        return (string) ($validated['business_date'] ?? now()->toDateString());
    }
}
