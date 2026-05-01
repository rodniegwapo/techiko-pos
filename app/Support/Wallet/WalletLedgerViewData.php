<?php

namespace App\Support\Wallet;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\WalletCashMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class WalletLedgerViewData
{
    /**
     * @return array{
     *     movements: LengthAwarePaginator,
     *     filters: array<string, mixed>,
     *     ledgerBalance: float,
     *     railCardTypes: Collection<int, PaymentCardType>
     * }
     */
    public static function buildPaginated(Request $request, Domain $domain, InventoryLocation $location): array
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'payment_card_type_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_card_types,id'],
            'rail' => ['sometimes', 'nullable', 'string', 'in:cash_register'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', WalletCashMovement::KINDS)],
        ]);

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $filtered = self::baseQuery($request, $domain, $location, $validated);

        $inSum = (clone $filtered)->where('direction', 'in')->sum('amount');
        $outSum = (clone $filtered)->where('direction', 'out')->sum('amount');
        $ledgerBalance = round(((float) $inSum - (float) $outSum), 2);

        return [
            'movements' => $filtered->clone()
                ->with(['paymentCardType:id,name,domain', 'user:id,name'])
                ->latest('movement_date')
                ->latest('id')
                ->paginate($perPage)
                ->withQueryString(),
            'filters' => [
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'payment_card_type_id' => isset($validated['payment_card_type_id'])
                    ? (int) $validated['payment_card_type_id']
                    : null,
                'rail' => $request->input('rail'),
                'kind' => $validated['kind'] ?? null,
                'location_id' => $location->id,
            ],
            'ledgerBalance' => $ledgerBalance,
            'railCardTypes' => PaymentCardType::query()
                ->forDomainLocation($domain->name_slug, $location->id)
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public static function todayManualNet(Domain $domain, InventoryLocation $location, string $dateYmd): float
    {
        $base = WalletCashMovement::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('movement_date', $dateYmd);
        $inSum = (clone $base)->where('direction', 'in')->sum('amount');
        $outSum = (clone $base)->where('direction', 'out')->sum('amount');

        return round(((float) $inSum - (float) $outSum), 2);
    }

    public static function runningCashBalance(Domain $domain, InventoryLocation $location): float
    {
        $base = WalletCashMovement::query()->forWalletContext($domain->name_slug, $location->id);
        $inSum = (clone $base)->where('direction', 'in')->sum('amount');
        $outSum = (clone $base)->where('direction', 'out')->sum('amount');

        return round(((float) $inSum - (float) $outSum), 2);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function baseQuery(Request $request, Domain $domain, InventoryLocation $location, array $validated): Builder
    {
        $q = WalletCashMovement::query()->forWalletContext($domain->name_slug, $location->id);

        if (! empty($validated['date_from'])) {
            $q->whereDate('movement_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $q->whereDate('movement_date', '<=', $validated['date_to']);
        }

        $railFilter = $request->input('rail');
        if ($railFilter === 'cash_register') {
            $q->whereNull('payment_card_type_id');
        } elseif (! empty($validated['payment_card_type_id'])) {
            $type = PaymentCardType::query()->findOrFail($validated['payment_card_type_id']);
            self::ensureCardTypeInDomainLocation($domain, $location, $type);
            $q->where('payment_card_type_id', $validated['payment_card_type_id']);
        }

        if (! empty($validated['kind'])) {
            $q->where('kind', $validated['kind']);
        }

        return $q;
    }

    public static function ensureCardTypeInDomainLocation(Domain $domain, InventoryLocation $location, PaymentCardType $paymentCardType): void
    {
        if (
            $paymentCardType->domain !== $domain->name_slug
            || (int) $paymentCardType->location_id !== (int) $location->id
        ) {
            abort(403);
        }
    }
}
