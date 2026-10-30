<?php

namespace App\Support\Wallet;

use Illuminate\Support\Collection;

/**
 * Where money is, for supervisors and up: each place (the cash drawer, then each payment channel)
 * with its balance and who it came from on a day, and the same money by cashier.
 *
 * - The drawer, today: each cashier's own tray (CashierDrawers), and what the store holds outside
 *   any shift. Another day: each cashier's cash sales that day.
 * - A channel: each cashier's takings into it that day (MoneyByUser).
 * Whatever isn't from a cashier that day (earlier days, transfers, corrections) is the "rest", so
 * every place's split adds up to its balance.
 */
final class MoneyByPlace
{
    public const DRAWER = 'drawer';

    /**
     * @param  Collection<int, array{id: int, name: string, kind: string, is_active: bool, balance: float}>  $channels
     * @return array{date: string, is_today: bool, places: list<array<string, mixed>>, cashiers: list<array<string, mixed>>, store: array<string, float>|null, columns: list<array{key: string, name: string}>}
     */
    public static function build(string $domainSlug, int $locationId, string $date, float $drawerExpected, Collection $channels): array
    {
        $isToday = $date === now()->toDateString();
        $byUser = collect(MoneyByUser::build($domainSlug, $locationId, $date));
        $trays = $isToday ? CashierDrawers::build($domainSlug, $locationId, $date, $drawerExpected) : null;
        $trayByUser = collect($trays['drawers'] ?? [])->keyBy('user.id');

        // Each user's latest shift that day gives their status.
        $status = $byUser->mapWithKeys(fn ($u) => [$u['user']['id'] => collect($u['shifts'])->last()['status'] ?? null]);
        $names = $byUser->mapWithKeys(fn ($u) => [$u['user']['id'] => $u['user']['name']])
            ->union($trayByUser->mapWithKeys(fn ($d) => [$d['user']['id'] => $d['user']['name']]));
        // What each user took into each place that day: "cash" for the drawer, "channel:{id}" for a channel.
        $takings = $byUser->mapWithKeys(fn ($u) => [$u['user']['id'] => collect($u['places'])->mapWithKeys(fn ($p) => [
            self::placeKey($p['key']) => $p['amount'],
        ])]);

        $drawerAmount = fn (int $userId) => $isToday
            ? (float) ($trayByUser[$userId]['amount'] ?? 0)
            : (float) ($takings[$userId]['cash'] ?? 0);

        $split = function (string $key, callable $amountFor) use ($names, $status) {
            return $names->map(fn ($name, $userId) => [
                'user' => ['id' => (int) $userId, 'name' => $name],
                'status' => $status[$userId] ?? null,
                'amount' => round($amountFor((int) $userId), 2),
            ])->filter(fn ($row) => $row['amount'] != 0)->sortBy('user.name')->values();
        };

        $drawerSplit = $split(self::DRAWER, $drawerAmount);
        $places = collect([[
            'key' => self::DRAWER,
            'name' => 'Cash drawer',
            'kind' => 'drawer',
            'channel' => null,
            'balance' => round($drawerExpected, 2),
            'split' => $drawerSplit->all(),
            'rest' => [
                'label' => $isToday ? 'Store (not in a shift)' : 'Earlier / not from a shift',
                'amount' => round($drawerExpected - $drawerSplit->sum('amount'), 2),
            ],
        ]]);

        foreach ($channels as $channel) {
            $key = 'channel:'.$channel['id'];
            $rows = $split($key, fn (int $userId) => (float) ($takings[$userId][$key] ?? 0));
            $places->push([
                'key' => $key,
                'name' => $channel['name'],
                'kind' => $channel['kind'],
                'channel' => $channel,
                'balance' => round((float) $channel['balance'], 2),
                'split' => $rows->all(),
                'rest' => [
                    'label' => 'Earlier / not from a shift',
                    'amount' => round((float) $channel['balance'] - $rows->sum('amount'), 2),
                ],
            ]);
        }

        $columns = $places->map(fn ($p) => ['key' => $p['key'], 'name' => $p['name']])->values();
        $cashiers = $names->map(function ($name, $userId) use ($columns, $drawerAmount, $takings, $status) {
            $cells = $columns->mapWithKeys(fn ($c) => [$c['key'] => round($c['key'] === self::DRAWER
                ? $drawerAmount((int) $userId)
                : (float) ($takings[$userId][$c['key']] ?? 0), 2)]);
            $credit = round((float) ($takings[$userId]['credit'] ?? 0), 2);

            return [
                'user' => ['id' => (int) $userId, 'name' => $name],
                'status' => $status[$userId] ?? null,
                'cells' => $cells->all(),
                'credit' => $credit,
                'total' => round($cells->sum() + $credit, 2),
            ];
        })->sortBy('user.name')->values();

        return [
            'date' => $date,
            'is_today' => $isToday,
            'places' => $places->all(),
            'cashiers' => $cashiers->all(),
            // Today: what the store's drawer holds outside every cashier's tray.
            'store' => $isToday ? [self::DRAWER => $places->first()['rest']['amount']] : null,
            'columns' => $columns->all(),
        ];
    }

    /** MoneyByUser's "method:channel_id" as a place: the drawer's cash, a channel, or credit. */
    private static function placeKey(string $moneyByUserKey): string
    {
        [$method, $channelId] = array_pad(explode(':', $moneyByUserKey, 2), 2, '');

        return match (true) {
            $method === 'cash' => 'cash',
            $method === 'credit' => 'credit',
            $channelId !== '' => 'channel:'.$channelId,
            default => $method,
        };
    }
}
