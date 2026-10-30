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
use App\Support\Wallet\DrawerByShift;
use App\Support\Wallet\MoneyByPlace;
use App\Support\Wallet\MoneyByUser;
use App\Support\Wallet\MoneyTimeline;
use App\Support\Wallet\RegisterShiftService;
use App\Support\Wallet\StoresCash;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Cash & wallet, one job per page (tabs under one sidebar entry):
 *  - Today's drawer: my shift; for supervisors and up every cashier's shift, approve, end the day.
 *  - Where money is: how much is in the drawer and each payment channel now, by place and cashier.
 *  - Cash in & out: every line in and out (this store, or all places for managers), adding cash by hand.
 *  - Shifts & counts: past cashier shifts to review, and the store's counts by day (was Drawer history).
 *  - Cash on hand (owners): the cash collected from the drawers.
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
        // short or over: that, and the count history, stay with managers and whoever approves counts.
        $canApprove = RoutePermission::allows($request->user(), 'register-shifts.approve');
        $canSeeCountResult = FinanceAccess::canManage($request->user()) || $canApprove;
        if (! $canSeeCountResult) {
            $cashControl = [
                ...collect($cashControl)->reject(fn ($v, $key) => str_starts_with($key, 'bridge_'))->all(),
                'variance' => null,
                'count_submission_history' => [],
            ];
        }
        // Each cashier has their own tray: a cashier sees only their shift, not the store's total.
        $canSeeStoreDrawer = FinanceAccess::canOverseeDrawer($request->user());
        if (! $canSeeStoreDrawer) {
            $cashControl = collect($cashControl)->only([
                'business_date', 'is_closed', 'closed_at', 'closed_by', 'can_reopen', 'closed_by_name', 'last_reopen',
            ])->all();
        }

        $user = $request->user();
        $shifts = app(RegisterShiftService::class);
        $todayByUser = collect(MoneyByUser::build($domain->name_slug, (int) $location->id, now()->toDateString()));
        $needsReview = $canApprove
            ? RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
                ->where('status', RegisterShift::NEEDS_REVIEW)->with('user:id,name')->orderBy('closed_at')->get()
            : collect();
        $mine = RegisterShift::openFor($domain->name_slug, (int) $location->id, (int) $user->id)
            ?? RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
                ->where('user_id', $user->id)->whereDate('business_date', now()->toDateString())
                ->latest('id')->first();

        return Inertia::render('Wallet/Drawer', [
            ...$this->pageProps($request, $domain, $location),
            'cashControl' => $cashControl,
            'canSeeCountResult' => $canSeeCountResult,
            'canSeeStoreDrawer' => $canSeeStoreDrawer,
            // Supervisors and up: every cashier's shift that day, adding up to the store's Expected.
            'drawerByShift' => $canSeeStoreDrawer
                ? DrawerByShift::build($domain->name_slug, (int) $location->id, (string) $cashControl['business_date'], (float) $cashControl['expected_cash'])
                : null,
            'canApprove' => $canApprove,
            'currentUserId' => (int) $request->user()->id,
            // The user's own takings today by where they went (their drawer, GCash, card...): everyone's
            // own drawer comes first; the store's is the table below for supervisors and up.
            'myTakings' => $todayByUser->firstWhere('user.id', (int) $request->user()->id)['places'] ?? [],
            // The day at a glance, above the shift table (supervisors and up).
            'drawerSummary' => $canSeeStoreDrawer ? [
                'cash_in_drawers' => (float) ($cashControl['expected_cash'] ?? 0),
                'cash_sales' => (float) ($cashControl['paid_cash_sales'] ?? 0),
                // Card, e-wallet and bank takings today: money that went past the drawer.
                'digital' => round((float) $todayByUser->flatMap(fn ($u) => $u['places'])
                    ->filter(fn ($p) => preg_match('/^(card|e-wallet|bank):/', $p['key']))->sum('amount'), 2),
                'discrepancy' => $canApprove ? round((float) $needsReview->sum('variance'), 2) : null,
                'needs_review' => $canApprove ? $needsReview->count() : null,
            ] : null,
            // Counts waiting for a manager here, any day: approve or send back right on this page.
            'requiredActions' => $canApprove && $canSeeStoreDrawer
                ? $needsReview->map(fn (RegisterShift $s) => $s->toRow())->values()
                : [],
            // An owner's own money: the cash they collected from the drawers, still the business's.
            // Every store's, not this one's: and what came in from the drawers today.
            'cashOnHand' => RoutePermission::allows($request->user(), 'cash-on-hand.index') ? [
                'balance' => CashOnHand::balance($domain->name_slug),
                'collected_today' => CashOnHand::collectedOn($domain->name_slug, now()->toDateString()),
            ] : null,
            // The user's own shift: open now, or the last one they ended today.
            'myShift' => $mine ? [
                ...$mine->load('reviewedBy:id,name')->toRow(),
                'figures' => $mine->isOpen() ? $shifts->figures($mine) : null,
                'can_reopen' => ! $mine->isOpen() && $mine->status !== RegisterShift::APPROVED
                    && RoutePermission::allows($user, 'register-shifts.reopen'),
                'last_spot_check' => RegisterShiftService::lastSpotCheck($mine),
            ] : null,
            'canSpotCheck' => RoutePermission::allows($user, 'register-shifts.spot-check'),
            // What the user's drawer still holds from their last shift today, to start the next one with.
            'nextShiftOpening' => $mine?->isOpen() ? null : $shifts->carriedOpening($domain->name_slug, (int) $location->id, (int) $user->id),
            'canStartShift' => RoutePermission::allows($user, 'register-shifts.start'),
            // Ending the store's day stays with managers, after every cashier has ended their shift of
            // that day; a shift forgotten from an earlier day (overdue) doesn't hold it up.
            'openShiftsAtStore' => RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
                ->open()->whereDate('business_date', '>=', (string) $cashControl['business_date'])
                ->with('user:id,name')->get()->map(fn (RegisterShift $s) => ['id' => $s->id, 'user' => $s->user?->name])->values(),
            'overdueShifts' => $canSeeStoreDrawer
                ? RegisterShift::query()->forWalletContext($domain->name_slug, (int) $location->id)
                    ->open()->whereDate('business_date', '<', now()->toDateString())->with('user:id,name')->orderBy('business_date')->get()
                    ->map(fn (RegisterShift $s) => [...$s->toRow(), 'expected_cash' => $shifts->figures($s)['expected_cash']])->values()
                : [],
            // Managers end a forgotten shift for the cashier, counting the tray themselves.
            'canEndForOthers' => FinanceAccess::canManage($user),
            // The badge that leads to Shifts & counts: ended shifts here a manager hasn't looked at yet.
            'needsReviewCount' => $canApprove ? $needsReview->count() : 0,
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

        $props = $this->pageProps($request, $domain, $location);
        // All places: every peso in and out across the business (what was Where money is → All money).
        $view = $request->query('view') === 'all' && $props['canViewAllMoney'] ? 'all' : 'store';

        return Inertia::render('Wallet/CashInOut', [
            ...$props,
            'view' => $view,
            'allMoney' => $view === 'all' ? MoneyTimeline::build($request, $domain, $location) : null,
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

        // All money moved to Cash in & out (All places): old links land there.
        if ($request->query('tab') === 'all') {
            return redirect()->route('domains.wallet.cash-in-out', [
                'domain' => $domain->name_slug,
                ...collect($request->query())->except('tab')->all(),
                'view' => 'all',
            ]);
        }

        $canSeeAllStores = FinanceAccess::canSeeAllStores($user);
        $tab = $request->query('tab') === 'stores' && $canSeeAllStores ? 'stores' : 'balances';
        $canSeeBalances = RoutePermission::allows($user, 'wallet-cash-ledger.index');
        $today = now()->toDateString();
        $drawerExpected = $canSeeBalances
            ? CashControlSnapshot::build($domain, $location, $today, (int) $user->id)['expected_cash']
            : null;
        $channelBalances = $canSeeBalances ? ChannelBalances::forLocation($domain->name_slug, (int) $location->id) : collect();
        // Who each place's money came from on a day (today unless picked): supervisors and up.
        $splitDate = $request->validate(['date' => ['sometimes', 'date', 'before_or_equal:today']])['date'] ?? null;
        $splitDate = $splitDate ? Carbon::parse($splitDate)->toDateString() : $today;

        return Inertia::render('Wallet/Balances', [
            ...$props,
            'tab' => $tab,
            'canSeeBalances' => $canSeeBalances,
            // The drawer: today's Expected, the same figure as Today's drawer.
            'drawerExpected' => $drawerExpected,
            // Each place by cashier (each has their own tray), for those who see money by user.
            'moneyByPlace' => $canSeeBalances && RoutePermission::allows($user, 'register-shifts.money-by-user')
                ? MoneyByPlace::build($domain->name_slug, (int) $location->id, $splitDate, (float) $drawerExpected, $channelBalances)
                : null,
            'channelBalances' => $channelBalances,
            'todayIsClosed' => WalletShiftGuard::isClosed($domain->name_slug, (int) $location->id, now()->toDateString()),
            // Every store's drawer and channels side by side: for owners and admins.
            'canSeeAllStores' => $canSeeAllStores,
            'allStores' => $tab === 'stores' ? AllStoresMoney::build($domain, (int) $user->id) : null,
        ]);
    }

    /** Drawer history became Shifts & counts' store counts: old links land there. */
    public function history(Request $request, Domain $domain)
    {
        return redirect()->route('domains.register-shifts.index', [
            'domain' => $domain->name_slug,
            ...$request->query(),
            'view' => 'store',
        ]);
    }

    /**
     * Shifts & counts, for checking back. Cashier shifts: every cashier's shift with its count and
     * difference, the ones waiting for review first, for a manager to approve or send back. Store
     * counts: the store's counts and opening changes by day and the days it ended (what Drawer
     * history was). Each part shows with its own permission.
     */
    public function counts(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $user = $request->user();
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['needs_review', 'closed', 'approved', 'open', 'all'])],
            'view' => ['sometimes', 'string', Rule::in(['shifts', 'store'])],
        ]);
        $status = $validated['status'] ?? RegisterShift::NEEDS_REVIEW;
        // The page opens with either permission (RoutePermission::ALIASES): check each part's own.
        $canSeeShifts = $user->isSuperUser() || $user->getAllPermissions()->contains('route_name', 'register-shifts.index');
        $canSeeStore = RoutePermission::allows($user, 'wallet-cash-ledger.index');
        $view = ($validated['view'] ?? null) === 'store' && $canSeeStore ? 'store' : ($canSeeShifts ? 'shifts' : 'store');

        if ($view === 'store') {
            return Inertia::render('Wallet/Counts', [
                ...$this->pageProps($request, $domain, $location),
                'view' => 'store',
                'canSeeShifts' => $canSeeShifts,
                'canSeeStore' => true,
                'cashControl' => CashControlSnapshot::build($domain, $location, $this->businessDate($request), (int) $user->id),
                'closedShifts' => StoresCash::build($request, $domain, (int) $location->id),
            ]);
        }

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
            ]);

        return Inertia::render('Wallet/Counts', [
            ...$this->pageProps($request, $domain, $location),
            'view' => 'shifts',
            'canSeeShifts' => true,
            'canSeeStore' => $canSeeStore,
            'status' => $status,
            'shifts' => $shifts,
            'statusCounts' => (clone $base)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'canApprove' => RoutePermission::allows($user, 'register-shifts.approve'),
            'currentUserId' => (int) $user->id,
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
