<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PaymentCardType;
use App\Models\WalletCashMovement;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletCashMovementController extends Controller
{
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
            'movement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

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
}
