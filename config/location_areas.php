<?php

/*
|--------------------------------------------------------------------------
| What a store and a warehouse show
|--------------------------------------------------------------------------
| A warehouse holds and moves stock and is run by supervisors and up: it sees the whole business
| (dashboard, sales history, finance across stores) but not the selling floor. A store sells and
| leaves the books to the warehouse. Selling screens themselves are kept out of warehouses by
| EnsureSellableLocation.
|
| Areas are matched by route-name prefix; the sidebar uses the same areas (useNavMenu.js).
*/

return [
    'areas' => [
        // The books. Expenses stay with the stores: register expenses are paid there.
        'finance' => ['domains.finance.', 'domains.profit-loss.', 'domains.vat-report.'],
        // Serving customers at the counter.
        'selling_floor' => ['domains.customers.', 'domains.loyalty.', 'domains.voids.'],
        // A store's card terminals and e-wallets, and what they hold.
        'payment_channels' => ['domains.payment-card-types.', 'domains.wallet.balances'],
        // A store's cash drawer, day by day. (Today's drawer itself shows a warehouse the stores' closed shifts.)
        'cash_drawer' => ['domains.wallet.cash-in-out', 'domains.wallet.history'],
    ],

    'hidden_at' => [
        // Only when the organization has a warehouse to keep its books at, and not for owners and
        // admins, who see the books at any store.
        'store' => ['finance'],
        'warehouse' => ['selling_floor', 'payment_channels', 'cash_drawer'],
    ],
];
