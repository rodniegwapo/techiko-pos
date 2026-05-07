<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PaymentCardType;
use App\Models\Sale;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashBridgeExpected;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WalletCashMovementController extends Controller
{
    private const AUTO_OPENING_LEDGER_NOTE = 'AUTO_CC_OPENING';

    private const AUTO_COUNTED_VARIANCE_LEDGER_NOTE = 'AUTO_CC_COUNTED_VARIANCE';

    private const AUTO_ENDSHIFT_CASHOUT_LEDGER_NOTE = 'AUTO_CC_ENDSHIFT_CASHOUT';

    private function ensureDateNotClosed(string $domainSlug, int $locationId, string $dateYmd): void
    {
        $closed = WalletCashReconciliation::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('business_date', $dateYmd)
            ->where('is_closed', true)
            ->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                'business_date' => 'Shift is closed for this date/location. Reopen to edit.',
            ]);
        }
    }

    public function index(Request $request, Domain $domain)
    {
        $qs = $request->getQueryString();

        return redirect()->to(
            route('domains.payment-card-types.index', ['domain' => $domain]).($qs ? '?'.$qs : '')
        );
    }

    public function store(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:in,out'],
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
                    (string) $request->input('kind') === 'owner_draw'
                    && (string) $request->input('draw_source') === 'card_type'
                ),
            ],
            'movement_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
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

        WalletCashMovement::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
            'payment_card_type_id' => $paymentCardTypeId,
            'direction' => $validated['direction'],
            'amount' => $validated['amount'],
            'kind' => $validated['kind'],
            'notes' => $validated['notes'] ?? null,
            'movement_date' => $validated['movement_date'],
            'user_id' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Ledger entry saved.');
    }

    public function setOpeningCash(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->ensureDateNotClosed($domain->name_slug, (int) $location->id, (string) $validated['business_date']);

        $newOpening = round((float) $validated['opening_cash'], 2);
        $recon = WalletCashReconciliation::query()->firstOrNew([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
            'business_date' => $validated['business_date'],
        ]);
        $oldOpening = $recon->exists ? (float) $recon->opening_cash : null;

        $recon->opening_cash = $newOpening;
        $recon->opening_source = 'manual';
        $recon->opening_source_date = null;
        $recon->save();

        WalletCashOpeningAudit::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
            'business_date' => $validated['business_date'],
            'reconciliation_id' => $recon->id,
            'old_opening_cash' => $oldOpening,
            'new_opening_cash' => $newOpening,
            'delta_amount' => round($newOpening - (float) ($oldOpening ?? 0), 2),
            'changed_by' => $request->user()->id,
            'changed_at' => now(),
            'reason' => $validated['reason'] ?? null,
        ]);

        WalletCashMovement::query()->updateOrCreate(
            [
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'movement_date' => $validated['business_date'],
                'kind' => 'adjustment',
                'notes' => self::AUTO_OPENING_LEDGER_NOTE,
            ],
            [
                'payment_card_type_id' => null,
                'direction' => 'in',
                'amount' => $newOpening,
                'user_id' => $request->user()->id,
            ]
        );

        return redirect()->back()->with('success', 'Opening cash saved.');
    }

    public function submitCountedCash(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->ensureDateNotClosed($domain->name_slug, (int) $location->id, (string) $validated['business_date']);

        $recon = WalletCashReconciliation::query()->updateOrCreate(
            [
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'business_date' => $validated['business_date'],
            ],
            [
                'counted_cash' => round((float) $validated['counted_cash'], 2),
                'notes' => $validated['notes'] ?? null,
                'counted_by' => $request->user()->id,
                'counted_at' => now(),
            ]
        );

        WalletCashMovement::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('movement_date', $validated['business_date'])
            ->where('notes', self::AUTO_COUNTED_VARIANCE_LEDGER_NOTE)
            ->delete();

        $paidCashSales = (float) Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('location_id', $location->id)
            ->where('payment_status', 'paid')
            ->where('payment_method', 'cash')
            ->whereDate('transaction_date', $validated['business_date'])
            ->sum('grand_total');

        $movementBase = WalletCashMovement::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('movement_date', $validated['business_date'])
            ->where(function ($q) {
                $q->whereNull('notes')
                    ->orWhere('notes', 'not like', 'AUTO_CC_%');
            });

        $manualIn = (float) (clone $movementBase)->where('direction', 'in')->sum('amount');
        $manualOut = (float) (clone $movementBase)->where('direction', 'out')->sum('amount');
        $openingCash = round((float) ($recon->opening_cash ?? 0), 2);
        $expectedCash = round($openingCash + $paidCashSales + $manualIn - $manualOut, 2);
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
                'user_id' => $request->user()->id,
            ]);
        }

        return redirect()->back()->with('success', 'Counted cash saved.');
    }

    public function endShift(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
            'end_shift_action' => ['required', 'string', Rule::in(['cashout_now', 'save_as_opening_cash'])],
        ]);

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
            return redirect()->back()->with('success', 'Shift is already closed.');
        }

        $action = (string) $validated['end_shift_action'];
        $countedCash = round((float) $recon->counted_cash, 2);

        if ($action === 'cashout_now') {
            WalletCashMovement::query()->create([
                'domain' => $domain->name_slug,
                'location_id' => $location->id,
                'payment_card_type_id' => null,
                'direction' => 'out',
                'amount' => $countedCash,
                'kind' => 'owner_draw',
                'notes' => self::AUTO_ENDSHIFT_CASHOUT_LEDGER_NOTE,
                'movement_date' => $validated['business_date'],
                'user_id' => $request->user()->id,
            ]);
        }

        if ($action === 'save_as_opening_cash') {
            $oldOpening = $recon->opening_cash !== null ? (float) $recon->opening_cash : null;
            $recon->opening_cash = $countedCash;
            $recon->opening_source = 'manual';
            $recon->opening_source_date = null;
            $recon->counted_cash = 0;
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
                'changed_by' => $request->user()->id,
                'changed_at' => now(),
                'reason' => 'Set from End Shift action: save_as_opening_cash.',
            ]);
        }

        $recon->is_closed = true;
        $recon->closed_at = now();
        $recon->closed_by = $request->user()->id;
        $recon->save();

        return redirect()->back()->with('success', 'Shift closed.');
    }

    public function reopenShift(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $recon = WalletCashReconciliation::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('business_date', $validated['business_date'])
            ->firstOrFail();

        if (! $recon->is_closed) {
            return redirect()->back()->with('success', 'Shift is already open.');
        }

        if ((int) $recon->closed_by !== (int) $request->user()->id) {
            abort(403);
        }

        $recon->is_closed = false;
        $recon->reopened_at = now();
        $recon->reopened_by = $request->user()->id;
        $recon->closed_at = null;
        $recon->closed_by = null;
        $recon->save();

        return redirect()->back()->with('success', 'Shift reopened.');
    }
}
