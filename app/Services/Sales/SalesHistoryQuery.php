<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\User;
use App\Services\Reports\ProfitAndLossService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Completed sales (anything past the open-cart "pending" state) and their totals, as the Sales
 * History page shows them. The AI assistant answers sales questions from here too, so the two
 * always agree. Cashiers and other staff below manager only ever see their own sales.
 */
class SalesHistoryQuery
{
    /** Statuses a sale can have once it has left the cart. */
    public const STATUSES = ['paid', 'partial', 'refunded', 'voided'];

    public const PAYMENT_METHODS = ['cash', 'card', 'e-wallet', 'bank', 'credit', 'split'];

    /**
     * @param  array{start: Carbon, end: Carbon, location_id: ?int, user_id: ?int, payment_method: ?string, payment_status: ?string, search: ?string}  $filters
     */
    public static function baseQuery(string $domainSlug, array $filters): Builder
    {
        return Sale::query()
            ->where('domain', $domainSlug)
            ->whereBetween('transaction_date', [$filters['start'], $filters['end']])
            ->whereIn('payment_status', $filters['payment_status'] ? [$filters['payment_status']] : self::STATUSES)
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['user_id'], fn ($q, $id) => $q->where('user_id', $id))
            // A method also finds the sales paid partly that way ("split" finds every sale paid in parts).
            ->when($filters['payment_method'], fn ($q, $method) => $q->where(function ($q) use ($method) {
                $q->where('payment_method', $method)
                    ->orWhere(fn ($split) => $split->where('payment_method', 'split')
                        ->whereHas('payments', fn ($p) => $p->where('method', $method)));
            }))
            ->when($filters['search'], function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            });
    }

    /**
     * The totals at the top of Sales History. Voided sales stay in the list but count for nothing;
     * profit reveals product costs, so it is only added when $withProfit.
     *
     * @return array{sales_count: int, gross: float, discounts: float, vat: float, net: float, sales_with_voids: int, voided_sales: int, cogs?: float, profit?: float, items_missing_cost?: int}
     */
    public static function summary(Builder $query, bool $withProfit): array
    {
        $counted = (clone $query)->where('payment_status', '!=', 'voided');

        $totals = (clone $counted)
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as gross')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discounts')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as vat')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net')
            ->first();

        $summary = [
            'sales_count' => (int) $totals->sales_count,
            'gross' => round((float) $totals->gross, 2),
            'discounts' => round((float) $totals->discounts, 2),
            'vat' => round((float) $totals->vat, 2),
            'net' => round((float) $totals->net, 2),
            'sales_with_voids' => (clone $counted)->whereHas('saleItems', fn ($q) => $q->onlyTrashed())->count(),
            'voided_sales' => (clone $query)->where('payment_status', 'voided')->count(),
        ];

        if ($withProfit) {
            // Voided sales are excluded; voided lines are soft-deleted, so they drop out on their own.
            ['cogs' => $cogs, 'items_missing_cost' => $missingCost] = ProfitAndLossService::costOfGoodsSold($counted);
            $summary += [
                'cogs' => round($cogs, 2),
                'profit' => round((float) $totals->net - (float) $totals->vat - $cogs, 2),
                'items_missing_cost' => $missingCost,
            ];
        }

        return $summary;
    }

    /**
     * Managers and above see everyone's sales; supervisors and cashiers see only their own.
     * Goes by the assigned role's level: the users.role_level column defaults to 3 (manager)
     * for staff created in the app, so it can't tell a cashier apart.
     */
    public static function canSeeAllSales(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->is_super_user) {
            return true;
        }

        $roleLevel = $user->roles->min('level');

        return $roleLevel !== null ? (int) $roleLevel <= 3 : $user->isManagerOrAbove();
    }
}
