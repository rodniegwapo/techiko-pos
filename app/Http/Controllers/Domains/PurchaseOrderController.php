<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\Finance\SupplierPaymentService;
use App\Services\InventoryService;
use App\Support\FinanceAccess;
use App\Support\PaidFromAccount;
use App\Support\RouteFeature;
use App\Support\RoutePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Purchasing: order stock from a supplier for a store or warehouse, then receive it. Each delivery
 * brings the stock in (an inventory "purchase" movement) and puts its value on the order's
 * supplier bill, so it shows under Finance → Supplier bills to be paid. It can also be paid
 * right away (Pay now), e.g. from a store's cash drawer.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SupplierPaymentService $payments,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;

        // The product picker on the order form asks here, so it needs no permission of its own.
        if ($request->expectsJson() && $request->filled('product_search')) {
            return response()->json([
                'data' => Product::query()->where('domain', $slug)
                    ->search($request->input('product_search'))
                    ->orderBy('name')->limit(20)
                    ->get(['id', 'name', 'SKU', 'cost']),
            ]);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(PurchaseOrder::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $orders = PurchaseOrder::query()->forDomain($slug)
            ->with(['supplier:id,name', 'location:id,name', 'lines.product:id,name,SKU', 'bill:id,purchase_order_id,amount,paid_amount'])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('po_number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $activeLocation = Helpers::getActiveLocation($domain);
        $canPayNow = $this->canPayNow($request->user(), $domain);
        $restrictedTo = FinanceAccess::restrictedLocationId($request->user());

        return Inertia::render('Purchasing/Index', [
            'orders' => $orders,
            'filters' => $validated,
            'suppliers' => Supplier::query()->forDomain($slug)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => InventoryLocation::query()->forDomain($slug)->active()->orderBy('name')->get(['id', 'name', 'type']),
            'defaultLocationId' => $activeLocation?->id,
            // Paying the supplier while receiving: only for those who may pay bills.
            'canPayNow' => $canPayNow,
            'paymentMethods' => $canPayNow ? Expense::PAYMENT_METHODS : [],
            'accounts' => $canPayNow
                ? FinancialAccount::query()->forDomain($slug)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])
                : [],
            'drawerLocations' => $canPayNow
                ? InventoryLocation::query()->forDomain($slug)->active()
                    ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
                    ->when($restrictedTo, fn ($q, $id) => $q->whereKey($id))
                    ->orderBy('name')->get(['id', 'name', 'type'])
                : [],
            'activeStoreId' => $activeLocation && ! $activeLocation->isWarehouse() ? $activeLocation->id : null,
        ]);
    }

    public function store(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validated($request, $domain);

        DB::transaction(function () use ($data, $domain, $request) {
            $order = PurchaseOrder::query()->create([
                'domain' => $domain->name_slug,
                'po_number' => PurchaseOrder::nextNumber($domain->name_slug),
                'supplier_id' => $this->supplierId($data, $domain),
                'location_id' => $data['location_id'],
                'status' => $data['send'] ? 'ordered' : 'draft',
                'ordered_at' => $data['send'] ? now() : null,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                ...$this->payFrom($data, $request->user()),
                'user_id' => $request->user()->id,
            ]);
            $order->lines()->createMany($this->lines($data));
        });

        return back()->with('success', $data['send'] ? 'Purchase order sent.' : 'Purchase order saved as a draft.');
    }

    public function update(Request $request, Domain $domain, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOwned($purchaseOrder, $domain);
        if ($purchaseOrder->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be edited.']);
        }
        $data = $this->validated($request, $domain);

        DB::transaction(function () use ($purchaseOrder, $data, $domain, $request) {
            $purchaseOrder->update([
                'supplier_id' => $this->supplierId($data, $domain),
                'location_id' => $data['location_id'],
                'status' => $data['send'] ? 'ordered' : 'draft',
                'ordered_at' => $data['send'] ? now() : null,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                ...$this->payFrom($data, $request->user()),
            ]);
            $purchaseOrder->lines()->delete();
            $purchaseOrder->lines()->createMany($this->lines($data));
        });

        return back()->with('success', 'Purchase order updated.');
    }

    /** A draft goes to the supplier. */
    public function order(Domain $domain, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOwned($purchaseOrder, $domain);
        if ($purchaseOrder->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be sent.']);
        }
        $purchaseOrder->update(['status' => 'ordered', 'ordered_at' => now()]);

        return back()->with('success', "{$purchaseOrder->po_number} sent.");
    }

    /** A delivery: the quantities that arrived, per line. */
    public function receive(Request $request, Domain $domain, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOwned($purchaseOrder, $domain);
        if (! $purchaseOrder->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only a sent order can be received.']);
        }

        // Pay now: today, from a store's cash drawer or another way. The rest stays on the bill.
        $payNow = $request->filled('pay_now');
        if ($payNow && ! $this->canPayNow($request->user(), $domain)) {
            throw ValidationException::withMessages(['pay_now' => 'You can\'t pay supplier bills. Receive it, and someone who can pays it under Finance → Supplier bills.']);
        }
        if ($payNow) {
            $request->merge(['pay_now' => [...(array) $request->input('pay_now'), 'payment_date' => today()->toDateString()]]);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            ...($payNow ? ['pay_now' => ['array'], ...SupplierPaymentService::rules($domain->name_slug, 'pay_now.')] : []),
        ]);

        $lines = $purchaseOrder->lines()->get()->keyBy('id');
        $arrived = collect($validated['lines'])
            ->filter(fn ($row) => (float) $row['quantity'] > 0)
            ->map(function ($row) use ($lines) {
                $line = $lines->get((int) $row['id']);
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => 'That line isn\'t on this order.']);
                }
                if ((float) $row['quantity'] > $line->remaining()) {
                    throw ValidationException::withMessages([
                        'lines' => "Only {$line->remaining()} of {$line->product?->name} is still to come.",
                    ]);
                }

                return ['line' => $line, 'quantity' => round((float) $row['quantity'], 2)];
            })
            ->values();

        if ($arrived->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Enter how many of something arrived.']);
        }

        $paid = DB::transaction(function () use ($purchaseOrder, $arrived, $request, $validated) {
            $this->inventory->receiveInventory(
                $arrived->map(fn ($a) => [
                    'product_id' => $a['line']->product_id,
                    'quantity' => $a['quantity'],
                    'unit_cost' => $a['line']->unit_cost,
                    'notes' => "Received on {$purchaseOrder->po_number}",
                ])->all(),
                $request->user(),
                $purchaseOrder->location,
                'PurchaseOrder',
                $purchaseOrder->id,
            );

            foreach ($arrived as $a) {
                $a['line']->increment('quantity_received', $a['quantity']);
            }

            $complete = $purchaseOrder->lines()->get()->every(fn ($l) => $l->remaining() <= 0);
            $purchaseOrder->update([
                'status' => $complete ? 'received' : 'partial',
                'received_at' => $complete ? now() : null,
            ]);

            $bill = $this->syncBill($purchaseOrder, $request->user()->id);

            // A failed payment (too much, closed shift…) undoes the delivery too.
            return isset($validated['pay_now'])
                ? $this->payments->record($bill, $validated['pay_now'], $request->user(), 'pay_now.')
                : null;
        });

        if ($paid) {
            return back()->with('success', 'Delivery received and ₱'.number_format((float) $paid->amount, 2).' paid to '.$purchaseOrder->supplier?->name.'.');
        }

        return back()->with('success', 'Delivery received: stock and the supplier bill are updated.');
    }

    /** Stops an order; what already arrived stays, with its bill. */
    public function cancel(Domain $domain, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureOwned($purchaseOrder, $domain);
        if (! in_array($purchaseOrder->status, ['draft', 'ordered', 'partial'], true)) {
            throw ValidationException::withMessages(['status' => 'This order can no longer be cancelled.']);
        }
        $purchaseOrder->update(['status' => 'cancelled']);

        return back()->with('success', "{$purchaseOrder->po_number} cancelled.");
    }

    /** The bill holds what has been received so far, at the order's costs. */
    private function syncBill(PurchaseOrder $order, int $userId): SupplierBill
    {
        $order->load(['lines', 'supplier']);
        $amount = round($order->lines->sum(fn ($l) => $l->quantity_received * $l->unit_cost), 2);

        $bill = $order->bill()->first();
        if ($bill) {
            $bill->update(['amount' => max($amount, (float) $bill->paid_amount)]);
            $bill->refreshPaid();

            return $bill;
        }

        $terms = (int) ($order->supplier?->payment_terms_days ?? 0);

        return SupplierBill::query()->create([
            'domain' => $order->domain,
            'location_id' => $order->location_id,
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'bill_number' => $order->po_number,
            'bill_date' => today()->toDateString(),
            'due_date' => $terms > 0 ? today()->addDays($terms)->toDateString() : null,
            'bill_type' => 'inventory',
            'amount' => $amount,
            'notes' => "Stock received on {$order->po_number}",
            'user_id' => $userId,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Domain $domain): array
    {
        $slug = $domain->name_slug;

        return $request->validate([
            'supplier_id' => ['nullable', 'required_without:supplier_name', 'integer', Rule::exists('suppliers', 'id')->where('domain', $slug)],
            'supplier_name' => ['nullable', 'required_without:supplier_id', 'string', 'max:255'],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('domain', $slug)->where('is_active', true)],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'send' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('domain', $slug)],
            'lines.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            // How it will be paid (optional): Receive → Pay now starts from it.
            'pay_method' => ['nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'pay_location_id' => [
                'nullable',
                'required_if:pay_method,cash_register',
                'integer',
                Rule::exists('inventory_locations', 'id')->where('domain', $slug)
                    ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse')),
            ],
            'pay_account_id' => PaidFromAccount::rules($slug),
        ], [
            'pay_location_id.required_if' => 'Choose which store\'s cash drawer will pay.',
            'lines.required' => 'Add at least one product.',
            'lines.*.product_id.distinct' => 'Each product goes on the order once.',
        ]) + ['send' => $request->boolean('send')];
    }

    /** @return list<array<string, mixed>> */
    private function lines(array $data): array
    {
        return array_map(fn ($line) => [
            'product_id' => (int) $line['product_id'],
            'quantity_ordered' => round((float) $line['quantity_ordered'], 2),
            'unit_cost' => round((float) $line['unit_cost'], 4),
        ], $data['lines']);
    }

    /** An existing supplier, or a new one typed on the order. */
    private function supplierId(array $data, Domain $domain): int
    {
        if (! empty($data['supplier_id'])) {
            return (int) $data['supplier_id'];
        }

        return (int) Supplier::query()->firstOrCreate(
            ['domain' => $domain->name_slug, 'name' => trim($data['supplier_name'])],
            ['is_active' => true],
        )->id;
    }

    /**
     * The order's "Pay from", kept only for those who may pay bills; a manager only names their
     * own store's drawer.
     *
     * @return array{pay_method: ?string, pay_location_id: ?int, pay_account_id: ?int}
     */
    private function payFrom(array $data, User $user): array
    {
        $method = $data['pay_method'] ?? null;
        if (! $method || ! RoutePermission::allows($user, 'finance.bill-payments.store')) {
            return ['pay_method' => null, 'pay_location_id' => null, 'pay_account_id' => null];
        }

        $locationId = $method === 'cash_register' ? (int) $data['pay_location_id'] : null;
        $restrictedTo = FinanceAccess::restrictedLocationId($user);
        if ($locationId && $restrictedTo !== null && $locationId !== $restrictedTo) {
            throw ValidationException::withMessages(['pay_location_id' => 'You can only pay from your own store\'s cash drawer.']);
        }

        return [
            'pay_method' => $method,
            'pay_location_id' => $locationId,
            'pay_account_id' => PaidFromAccount::keep($method, $data['pay_account_id'] ?? null),
        ];
    }

    /** Paying a bill needs the bill-payment permission and Finance. */
    private function canPayNow(?User $user, Domain $domain): bool
    {
        return RoutePermission::allows($user, 'finance.bill-payments.store')
            && RouteFeature::allows($domain, 'finance.bill-payments.store');
    }

    private function ensureOwned(PurchaseOrder $order, Domain $domain): void
    {
        abort_unless($order->domain === $domain->name_slug, 404);
    }
}
