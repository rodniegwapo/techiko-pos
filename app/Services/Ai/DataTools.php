<?php

namespace App\Services\Ai;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\MandatoryDiscount;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VoidLog;
use App\Services\CreditService;
use App\Services\Finance\FinancialReportService;
use App\Services\Sales\SalesHistoryQuery;
use App\Support\RoutePermission;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/**
 * Read-only answers about the business's own records for the AI assistant: sales, stock,
 * customers, credit, voids, cash and discounts. Each tool is offered only to roles that may open
 * the page showing that data, and follows the page's rules: staff below manager see only their
 * own sales and no profit, and a user tied to one store sees only that store.
 */
class DataTools
{
    /** Each tool and the permissions that allow it (any one of them). */
    public const PERMISSIONS = [
        'sales_summary' => ['sales-history.index'],
        'find_sale' => ['sales-history.show'],
        'stock_levels' => ['inventory.products', 'inventory.index'],
        'low_stock' => ['inventory.index', 'inventory.products'],
        'stock_movements' => ['inventory.movements'],
        'find_customer' => ['customers.index', 'credits.index'],
        'overdue_credit' => ['credits.index'],
        'voids' => ['voids.index'],
        'expected_cash_today' => ['wallet.money-movement'],
        'list_discounts' => ['products.discounts.index', 'sales.index'],
    ];

    private const MAX_DAYS = 366;

    public function __construct(
        private readonly User $user,
        private readonly Domain $domain,
        private readonly ?InventoryLocation $location,
    ) {}

    /** @return array<string, array{0: string, 1: Closure}> Name => [description, schema] for the tools this user may use. */
    public function definitions(): array
    {
        $dates = fn (JsonSchema $schema) => [
            'start_date' => $schema->string()->description('First day, YYYY-MM-DD (default today)'),
            'end_date' => $schema->string()->description('Last day, YYYY-MM-DD (default the start date)'),
        ];
        $store = fn (JsonSchema $schema) => [
            'store_id' => $schema->string()->description('Store id from list_stores, or "all" for every store (default: the store the user works in)'),
        ];

        $all = [
            'sales_summary' => ['Sales totals for a period: number of sales, gross, discounts, VAT, net, average sale, totals by payment method and the top products; profit only for managers and above. Staff below manager only get their own sales.', fn (JsonSchema $s) => [...$dates($s), ...$store($s)]],
            'find_sale' => ['One sale by invoice number: date, cashier, customer, store, items (with voided lines), discounts, payment and status.', fn (JsonSchema $s) => [
                'invoice' => $s->string()->description('The invoice number, or part of it')->required(),
            ]],
            'stock_levels' => ['Stock of products matching a name, SKU or barcode: on hand, available and low stock level per store (up to 10 products).', fn (JsonSchema $s) => [
                'query' => $s->string()->description('Part of the product name, SKU or barcode')->required(),
                ...$store($s),
            ]],
            'low_stock' => ['Products that are low on stock or out of stock in a store.', fn (JsonSchema $s) => $store($s)],
            'stock_movements' => ['Recent stock changes of one product (sales, receipts, transfers, adjustments) with who and when. Get the product_id from find_products or stock_levels.', fn (JsonSchema $s) => [
                'product_id' => $s->integer()->description('Product id')->required(),
                'days' => $s->integer()->description('How many days back, 1 to 365 (default 30)'),
                ...$store($s),
            ]],
            'find_customer' => ['Customers matching a name, phone or email: loyalty points and tier, and their credit (limit, balance, available, overdue) when this user may see credit.', fn (JsonSchema $s) => [
                'query' => $s->string()->description('Part of the name, phone or email')->required(),
            ]],
            'overdue_credit' => ['Customers with overdue credit (utang) and how much is overdue.', fn () => []],
            'voids' => ['Voided items in a period: product, amount, reason, who voided it and who approved it.', fn (JsonSchema $s) => $dates($s)],
            'expected_cash_today' => ['How much cash should be in the drawer today (stores with an opened shift today).', fn (JsonSchema $s) => $store($s)],
            'list_discounts' => ['The active discounts and the mandatory discounts (PWD, Senior…) with their rates.', fn () => []],
        ];

        return array_filter($all, fn ($tool, $name) => $this->allows($name), ARRAY_FILTER_USE_BOTH);
    }

    public function handles(string $name): bool
    {
        return array_key_exists($name, self::PERMISSIONS);
    }

    public function allows(string $name): bool
    {
        foreach (self::PERMISSIONS[$name] ?? [] as $permission) {
            if (RoutePermission::allows($this->user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function run(string $name, array $input): array
    {
        if (! $this->allows($name)) {
            return ['error' => 'not_allowed', 'message' => 'This user\'s role cannot see that information.'];
        }

        try {
            return match ($name) {
                'sales_summary' => $this->salesSummary($input),
                'find_sale' => $this->findSale((string) ($input['invoice'] ?? '')),
                'stock_levels' => $this->stockLevels((string) ($input['query'] ?? ''), $input),
                'low_stock' => $this->lowStock($input),
                'stock_movements' => $this->stockMovements($input),
                'find_customer' => $this->findCustomer((string) ($input['query'] ?? '')),
                'overdue_credit' => $this->overdueCredit(),
                'voids' => $this->voids($input),
                'expected_cash_today' => $this->expectedCash($input),
                'list_discounts' => $this->discounts(),
            };
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function salesSummary(array $input): array
    {
        [$start, $end] = $this->period($input);
        $storeId = $this->storeId($input);
        $seesAll = SalesHistoryQuery::canSeeAllSales($this->user);

        $query = SalesHistoryQuery::baseQuery($this->domain->name_slug, [
            'start' => $start, 'end' => $end, 'location_id' => $storeId,
            'user_id' => $seesAll ? null : $this->user->id,
            'payment_method' => null, 'payment_status' => null, 'search' => null,
        ]);
        $summary = SalesHistoryQuery::summary($query, $seesAll);
        $counted = (clone $query)->where('payment_status', '!=', 'voided');

        $byMethod = (clone $counted)
            ->selectRaw('payment_method, COUNT(*) as sales, COALESCE(SUM(grand_total), 0) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => ['method' => $row->payment_method, 'sales' => (int) $row->sales, 'total' => round((float) $row->total, 2)])
            ->all();

        $topProducts = SaleItem::query()
            ->whereIn('sale_id', (clone $counted)->select('id'))
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(subtotal) as total')
            ->groupBy('product_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('product:id,name')
            ->get()
            ->map(fn ($row) => ['product' => $row->product?->name, 'quantity' => (int) $row->quantity, 'total' => round((float) $row->total, 2)])
            ->all();

        return [
            'period' => $this->describePeriod($start, $end),
            'store' => $this->storeName($storeId),
            'includes' => $seesAll ? 'all cashiers' : 'only this user\'s own sales',
            ...$summary,
            'average_sale' => $summary['sales_count'] > 0 ? round($summary['net'] / $summary['sales_count'], 2) : 0,
            'by_payment_method' => $byMethod,
            'top_products' => $topProducts,
            'note' => 'net is what customers paid (VAT included); voided sales are left out.',
        ];
    }

    private function findSale(string $invoice): array
    {
        $invoice = trim($invoice);
        if ($invoice === '') {
            throw new InvalidArgumentException('Give the invoice number.');
        }

        $sales = Sale::query()
            ->where('domain', $this->domain->name_slug)
            ->whereIn('payment_status', SalesHistoryQuery::STATUSES)
            ->where('invoice_number', 'like', '%'.$invoice.'%')
            ->when(! SalesHistoryQuery::canSeeAllSales($this->user), fn ($q) => $q->where('user_id', $this->user->id))
            ->when($this->restrictedStoreId(), fn ($q, $id) => $q->where('location_id', $id))
            ->with(['user:id,name', 'customer:id,name', 'location:id,name', 'payments'])
            ->latest('transaction_date')
            ->limit(3)
            ->get();

        if ($sales->isEmpty()) {
            return ['found' => false, 'message' => 'No sale with that invoice number that this user can see.'];
        }

        return ['found' => true, 'sales' => $sales->map(function (Sale $sale) {
            $items = SaleItem::withTrashed()->where('sale_id', $sale->id)->with('product:id,name')->get();

            return [
                'invoice' => $sale->invoice_number,
                'date' => optional($sale->transaction_date)->format('Y-m-d H:i'),
                'status' => $sale->payment_status,
                'cashier' => $sale->user?->name,
                'customer' => $sale->customer?->name ?? 'Walk-in',
                'store' => $sale->location?->name,
                'payment_method' => $sale->payment_method,
                'payments' => $sale->payments->map(fn ($p) => ['method' => $p->method, 'amount' => (float) $p->amount])->all(),
                'subtotal' => (float) $sale->total_amount,
                'discount' => (float) $sale->discount_amount,
                'vat' => (float) $sale->tax_amount,
                'total' => (float) $sale->grand_total,
                'items' => $items->map(fn (SaleItem $item) => [
                    'product' => $item->product?->name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount,
                    'subtotal' => (float) $item->subtotal,
                    'voided' => $item->trashed(),
                ])->all(),
            ];
        })->all()];
    }

    private function stockLevels(string $query, array $input): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Give part of the product name, SKU or barcode.');
        }
        $storeId = $this->storeId($input);

        $products = Product::query()->forDomain($this->domain->name_slug)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('SKU', $query)->orWhere('barcode', $query))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'SKU', 'reorder_level', 'track_inventory']);

        $stock = ProductInventory::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->when($storeId, fn ($q, $id) => $q->where('location_id', $id))
            ->with('location:id,name')
            ->get()
            ->groupBy('product_id');

        return [
            'store' => $this->storeName($storeId),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->SKU,
                'tracks_stock' => (bool) $p->track_inventory,
                'stores' => ($stock[$p->id] ?? collect())->map(fn (ProductInventory $i) => [
                    'store' => $i->location?->name,
                    'on_hand' => (float) $i->quantity_on_hand,
                    'available' => (float) $i->quantity_available,
                    'low_stock_level' => $i->location_reorder_level ?? $p->reorder_level,
                ])->values()->all(),
            ])->all(),
        ];
    }

    private function lowStock(array $input): array
    {
        $storeId = $this->storeId($input);
        $base = fn () => ProductInventory::query()
            ->whereHas('product', fn ($q) => $q->where('domain', $this->domain->name_slug))
            ->when($storeId, fn ($q, $id) => $q->where('location_id', $id));
        $row = fn (ProductInventory $i) => [
            'product' => $i->product?->name,
            'store' => $i->location?->name,
            'available' => (float) $i->quantity_available,
            'low_stock_level' => $i->location_reorder_level ?? $i->product?->reorder_level,
        ];

        // The same rule as the Inventory dashboard: at or under the store's low stock level, else the product's.
        $low = $base()->lowStock()->inStock();
        $out = $base()->outOfStock();

        return [
            'store' => $this->storeName($storeId),
            'low_stock_count' => (clone $low)->count(),
            'out_of_stock_count' => (clone $out)->count(),
            'low_stock' => $low->with(['product:id,name,reorder_level', 'location:id,name'])->orderBy('quantity_available')->limit(20)->get()->map($row)->all(),
            'out_of_stock' => $out->with(['product:id,name,reorder_level', 'location:id,name'])->limit(20)->get()->map($row)->all(),
        ];
    }

    private function stockMovements(array $input): array
    {
        $product = Product::query()->forDomain($this->domain->name_slug)->find($input['product_id'] ?? null);
        if (! $product) {
            throw new InvalidArgumentException('No such product in this business. Use find_products or stock_levels to get its id.');
        }
        $days = max(1, min(365, (int) ($input['days'] ?? 30)));
        $storeId = $this->storeId($input);

        $movements = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->when($storeId, fn ($q, $id) => $q->where('location_id', $id))
            ->where('created_at', '>=', now()->subDays($days))
            ->with(['user:id,name', 'location:id,name'])
            ->latest()
            ->limit(20)
            ->get();

        return [
            'product' => $product->name,
            'store' => $this->storeName($storeId),
            'days' => $days,
            'movements' => $movements->map(fn (InventoryMovement $m) => [
                'when' => $m->created_at?->format('Y-m-d H:i'),
                'type' => $m->movement_type_display,
                'change' => (int) $m->quantity_change,
                'after' => (int) $m->quantity_after,
                'store' => $m->location?->name,
                'by' => $m->user?->name,
                'reason' => $m->reason ?: $m->notes,
            ])->all(),
        ];
    }

    private function findCustomer(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new InvalidArgumentException('Give at least 2 letters of the name, phone or email.');
        }
        $seesCredit = RoutePermission::allows($this->user, 'credits.index');

        $customers = Customer::query()->forDomain($this->domain->name_slug)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('phone', 'like', '%'.$query.'%')->orWhere('email', 'like', '%'.$query.'%'))
            ->orderBy('name')
            ->limit(5)
            ->get();

        return ['customers' => $customers->map(function (Customer $c) use ($seesCredit) {
            $row = [
                'name' => $c->name,
                'phone' => $c->phone,
                'loyalty_points' => (int) $c->loyalty_points,
                'tier' => $c->tier,
                'lifetime_spent' => (float) $c->lifetime_spent,
            ];
            if ($seesCredit) {
                $row['credit'] = [
                    'enabled' => (bool) $c->credit_enabled,
                    'limit' => (float) $c->credit_limit,
                    'balance_owed' => (float) $c->credit_balance,
                    'available' => round($c->getAvailableCredit(), 2),
                    'overdue' => round($c->getTotalOverdueAmount(), 2),
                ];
            }

            return $row;
        })->all()];
    }

    private function overdueCredit(): array
    {
        $accounts = app(CreditService::class)->getOverdueAccounts($this->domain->name_slug);
        usort($accounts, fn ($a, $b) => $b['overdue_amount'] <=> $a['overdue_amount']);

        return [
            'customers' => count($accounts),
            'total_overdue' => round(array_sum(array_column($accounts, 'overdue_amount')), 2),
            'accounts' => array_map(fn ($a) => [
                'customer' => $a['customer']->name,
                'overdue' => round((float) $a['overdue_amount'], 2),
                'balance_owed' => (float) $a['customer']->credit_balance,
                'overdue_invoices' => $a['overdue_count'],
                'oldest_due_date' => $a['oldest_overdue_date'] ? Carbon::parse($a['oldest_overdue_date'])->toDateString() : null,
            ], array_slice($accounts, 0, 20)),
        ];
    }

    private function voids(array $input): array
    {
        [$start, $end] = $this->period($input);
        $storeId = $this->restrictedStoreId();

        $voids = VoidLog::query()
            ->whereHas('saleItem.sale', fn ($sale) => $sale->where('domain', $this->domain->name_slug)
                ->when($storeId, fn ($q, $id) => $q->where('location_id', $id)))
            ->whereBetween('created_at', [$start, $end])
            ->with(['user:id,name', 'approver:id,name', 'saleItem.product:id,name', 'saleItem.sale:id,invoice_number'])
            ->latest()
            ->limit(30)
            ->get();

        return [
            'period' => $this->describePeriod($start, $end),
            'count' => $voids->count(),
            'total_amount' => round((float) $voids->sum('amount'), 2),
            'voids' => $voids->map(fn (VoidLog $v) => [
                'when' => $v->created_at?->format('Y-m-d H:i'),
                'product' => $v->saleItem?->product?->name,
                'invoice' => $v->saleItem?->sale?->invoice_number,
                'amount' => (float) $v->amount,
                'reason' => $v->reason,
                'voided_by' => $v->user?->name,
                'approved_by' => $v->approver?->name,
            ])->all(),
        ];
    }

    private function expectedCash(array $input): array
    {
        $storeId = $this->storeId($input);
        $cash = app(FinancialReportService::class)->cashInDrawer($this->domain->name_slug, $storeId);

        return [
            'store' => $this->storeName($storeId),
            'expected_cash' => $cash['amount'],
            'stores_with_a_shift_today' => $cash['locations_counted'],
            'note' => $cash['locations_counted'] === 0
                ? 'No cash shift was opened today, so there is no expected amount yet (open one under Cash & wallet → Money movement).'
                : 'Opening cash plus cash sales and cash moved in, minus cash moved out, for today.',
        ];
    }

    private function discounts(): array
    {
        return [
            'discounts' => Discount::query()->forDomain($this->domain->name_slug)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
                ->orderBy('name')->limit(30)
                ->get(['name', 'type', 'scope', 'value', 'min_order_amount', 'start_date', 'end_date'])
                ->toArray(),
            'mandatory_discounts' => MandatoryDiscount::query()->forDomain($this->domain->name_slug)->where('is_active', true)
                ->orderBy('name')
                ->get(['name', 'type', 'value'])
                ->toArray(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(array $input): array
    {
        try {
            $start = Carbon::parse($input['start_date'] ?? today()->toDateString())->startOfDay();
            $end = Carbon::parse($input['end_date'] ?? $start->toDateString())->endOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }
        if ($end->lt($start)) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw new InvalidArgumentException('Ask for at most one year at a time.');
        }

        return [$start, $end];
    }

    private function describePeriod(Carbon $start, Carbon $end): string
    {
        return $start->isSameDay($end) ? $start->toDateString() : $start->toDateString().' to '.$end->toDateString();
    }

    /**
     * The store to look at: a user tied to one store always gets theirs; others get the one they
     * asked for (if it is this business's), every store for "all", else the store they work in.
     */
    private function storeId(array $input): ?int
    {
        if ($restricted = $this->restrictedStoreId()) {
            return $restricted;
        }

        $wanted = $input['store_id'] ?? null;
        if ($wanted === 'all') {
            return null;
        }
        if ($wanted !== null && $wanted !== '' && InventoryLocation::query()->forDomain($this->domain->name_slug)->whereKey((int) $wanted)->exists()) {
            return (int) $wanted;
        }

        return $this->location?->id;
    }

    private function restrictedStoreId(): ?int
    {
        if ($this->user->isSuperUser() || ! $this->user->hasLocationRestriction()) {
            return null;
        }

        return $this->user->getEffectiveLocationId(null) ?: null;
    }

    private function storeName(?int $storeId): string
    {
        return $storeId ? (InventoryLocation::query()->whereKey($storeId)->value('name') ?? 'this store') : 'all stores';
    }
}
