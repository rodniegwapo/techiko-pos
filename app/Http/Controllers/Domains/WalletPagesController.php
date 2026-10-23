<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Support\FinanceAccess;
use App\Support\RoutePermission;
use App\Support\Wallet\CashControlSnapshot;
use App\Support\Wallet\ChannelBalances;
use App\Support\Wallet\MoneyTimeline;
use App\Support\Wallet\StoresCash;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Http\Request;
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
            ]);
        }

        return Inertia::render('Wallet/Drawer', [
            ...$this->pageProps($request, $domain, $location),
            'cashControl' => CashControlSnapshot::build($domain, $location, $this->businessDate($request), (int) $request->user()->id),
        ]);
    }

    public function cashInOut(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $ledger = WalletLedgerViewData::buildPaginated($request, $domain, $location);

        return Inertia::render('Wallet/CashInOut', [
            ...$this->pageProps($request, $domain, $location),
            'ledger' => $ledger,
            'channels' => $ledger['railCardTypes'],
            'todayIsClosed' => WalletShiftGuard::isClosed($domain->name_slug, (int) $location->id, now()->toDateString()),
        ]);
    }

    public function balances(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $user = $request->user();
        $props = $this->pageProps($request, $domain, $location);

        $tab = $request->query('tab') === 'all' && $props['canViewAllMoney'] ? 'all' : 'balances';
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
