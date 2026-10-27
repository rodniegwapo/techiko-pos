<?php

namespace App\Support\Wallet;

use App\Models\PaymentCardType;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who took in what at a store on a day: for each user, the money from their sales by where it went
 * (the cash drawer, GCash, a bank account, a card terminal, customer credit), and their shifts with
 * what should be in their drawer. A sale belongs to the cashier whose shift took the payment, or,
 * from before shifts were per cashier, to whoever rang it up.
 */
final class MoneyByUser
{
    private const METHOD_LABELS = [
        'cash' => 'Cash drawer',
        'card' => 'Card',
        'e-wallet' => 'E-wallet',
        'bank' => 'Bank',
        'credit' => 'Credit (utang)',
    ];

    /**
     * @return list<array{user: array{id: int, name: string}, places: list<array{key: string, label: string, amount: float}>, total: float, shifts: list<array<string, mixed>>}>
     */
    public static function build(string $domainSlug, int $locationId, string $businessDate): array
    {
        $paidThatDay = fn () => Sale::query()
            ->leftJoin('register_shifts as rs', 'rs.id', '=', 'sales.register_shift_id')
            ->where('sales.domain', $domainSlug)
            ->where('sales.location_id', $locationId)
            ->where('sales.payment_status', 'paid')
            ->whereDate('sales.transaction_date', $businessDate);

        $whole = $paidThatDay()
            ->where('sales.payment_method', '!=', 'split')
            ->groupByRaw('COALESCE(rs.user_id, sales.user_id), sales.payment_method, sales.payment_card_type_id')
            ->selectRaw('COALESCE(rs.user_id, sales.user_id) AS owner_id, sales.payment_method AS method, sales.payment_card_type_id AS channel_id, SUM(sales.grand_total) AS total')
            ->get();

        $parts = SalePayment::query()
            ->whereIn('sale_payments.sale_id', $paidThatDay()->where('sales.payment_method', 'split')->select('sales.id'))
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->leftJoin('register_shifts as rs', 'rs.id', '=', 'sales.register_shift_id')
            ->groupByRaw('COALESCE(rs.user_id, sales.user_id), sale_payments.method, sale_payments.payment_card_type_id')
            ->selectRaw('COALESCE(rs.user_id, sales.user_id) AS owner_id, sale_payments.method AS method, sale_payments.payment_card_type_id AS channel_id, SUM(sale_payments.amount) AS total')
            ->get();

        $rows = $whole->concat($parts);
        $channels = PaymentCardType::query()->whereIn('id', $rows->pluck('channel_id')->filter()->unique())->pluck('name', 'id');

        $shifts = RegisterShift::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('business_date', $businessDate)
            ->orderBy('opened_at')
            ->get();

        $userIds = $rows->pluck('owner_id')->concat($shifts->pluck('user_id'))->filter()->unique();
        $users = User::query()->whereIn('id', $userIds)->pluck('name', 'id');
        $service = app(RegisterShiftService::class);

        return $userIds->map(function ($userId) use ($rows, $channels, $shifts, $users, $service) {
            $places = $rows->where('owner_id', $userId)
                ->groupBy(fn ($r) => $r->method.':'.($r->channel_id ?? ''))
                ->map(function (Collection $group, string $key) use ($channels) {
                    $first = $group->first();

                    return [
                        'key' => $key,
                        'label' => $first->channel_id && isset($channels[$first->channel_id])
                            ? $channels[$first->channel_id]
                            : (self::METHOD_LABELS[$first->method] ?? ucfirst((string) $first->method)),
                        'amount' => round((float) $group->sum('total'), 2),
                    ];
                })
                // The drawer first, then the channels by name.
                ->sortBy(fn ($p) => (str_starts_with($p['key'], 'cash:') ? '0' : '1').$p['label'])
                ->values();

            return [
                'user' => ['id' => (int) $userId, 'name' => (string) ($users[$userId] ?? 'Unknown user')],
                'places' => $places->all(),
                'total' => round((float) $places->sum('amount'), 2),
                'shifts' => $shifts->where('user_id', $userId)->map(fn (RegisterShift $s) => [
                    'id' => $s->id,
                    'status' => $s->status,
                    'opened_at' => $s->opened_at?->toIso8601String(),
                    'closed_at' => $s->closed_at?->toIso8601String(),
                    // Open: what should be in their drawer now. Ended: what it should have been, and the count.
                    'expected_cash' => $s->isOpen() ? $service->figures($s)['expected_cash'] : $s->expected_cash,
                    'counted_cash' => $s->counted_cash,
                ])->values()->all(),
            ];
        })->sortBy('user.name')->values()->all();
    }
}
