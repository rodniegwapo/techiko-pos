<?php

namespace App\Services\Ai\Actions\Finance;

use App\Models\Finance\SupplierBill;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\HasInputTemplate;
use App\Services\Ai\Actions\PreparedAction;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;

/** Records a payment on a supplier bill, like Finance → Bills to pay → Pay. */
class PaySupplierBill implements AssistantAction, HasInputTemplate
{
    use DescribesValues;

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'pay_supplier_bill';
    }

    public function permission(): string
    {
        return 'finance.bill-payments.store';
    }

    public function description(): string
    {
        return 'Propose recording a payment on an unpaid supplier bill. Give the bill by id (bill_id from list_open_bills), or the supplier by name when they have only one unpaid bill. The amount defaults to what is left to pay. Payment methods: '
            .collect(RecordExpense::METHODS)->map(fn ($label, $key) => $key.' = '.$label)->implode(', ').'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'bill_id' => $schema->integer()->description('Bill id from list_open_bills (or give supplier)'),
            'supplier' => $schema->string()->description('Supplier name, as the user wrote it (used when there is no bill_id)'),
            'amount' => $schema->number()->description('Amount paid in pesos (default: all that is left to pay)'),
            'payment_method' => $schema->string()->enum(array_keys(RecordExpense::METHODS))->description('How it was paid')->required(),
            'payment_date' => $schema->string()->description('Date paid, YYYY-MM-DD (default today; not in the future)'),
            'reference_no' => $schema->string()->description('Receipt or reference number (optional)'),
        ];
    }

    public function templateTitle(): string
    {
        return 'Pay supplier bill';
    }

    public function templateGuide(): ?string
    {
        return 'pay-supplier-bill';
    }

    public function template(ActionContext $context): array
    {
        $suppliers = $this->openBills($context)->pluck('supplier.name')->unique()->sort()->values();
        if ($suppliers->isEmpty()) {
            throw new ActionInputException('There are no unpaid supplier bills.');
        }

        return [
            ['label' => 'Supplier', 'required' => true, 'hint' => $this->namesHint($suppliers)],
            ['label' => 'Amount', 'required' => false, 'hint' => '₱; all that is left if blank'],
            ['label' => 'Paid with', 'required' => true, 'hint' => $this->namesHint(RecordExpense::METHODS)],
            ['label' => 'Date', 'required' => false, 'hint' => 'YYYY-MM-DD'],
            ['label' => 'Reference no.', 'required' => false, 'hint' => null],
        ];
    }

    /** @return Collection<int, SupplierBill> This business's unpaid bills, oldest due first, with their supplier. */
    public static function openBills(ActionContext $context): Collection
    {
        return SupplierBill::query()->where('domain', $context->domain->name_slug)->open()
            ->with('supplier:id,name')->orderBy('due_date')->orderBy('id')->get();
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $bill = $this->bill($input, $context);

        $amount = $input['amount'] ?? $bill->remaining;
        if (! is_numeric($amount) || $amount <= 0) {
            throw new ActionInputException('The amount paid must be more than 0.');
        }
        $amount = round((float) $amount, 2);
        if ($amount > $bill->remaining) {
            throw new ActionInputException('Only '.$this->peso($bill->remaining).' is left to pay on this bill.');
        }
        $method = $input['payment_method'] ?? null;
        if (! isset(RecordExpense::METHODS[$method])) {
            throw new ActionInputException('Say how it was paid: '.implode(', ', array_keys(RecordExpense::METHODS)).'.');
        }
        if ($method === 'cash_register' && (! $context->location || $context->location->isWarehouse())) {
            throw new ActionInputException('Cash from the register needs a store. The user should pick a store first.');
        }
        try {
            $date = Carbon::parse($input['payment_date'] ?? today()->toDateString())->toDateString();
        } catch (\Throwable) {
            throw new ActionInputException('The date must be YYYY-MM-DD.');
        }
        if ($date > today()->toDateString()) {
            throw new ActionInputException('A payment can\'t be dated in the future.');
        }
        $reference = trim((string) ($input['reference_no'] ?? '')) ?: null;

        $fields = [
            ['label' => 'Supplier', 'value' => $bill->supplier->name],
            ['label' => 'Bill', 'value' => ($bill->bill_number ? 'No. '.$bill->bill_number.', ' : '').'due '.$bill->due_date->toDateString()],
            ['label' => 'Amount', 'value' => $this->peso($amount).' of '.$this->peso($bill->remaining).' left'],
            ['label' => 'Paid with', 'value' => RecordExpense::METHODS[$method]],
            ['label' => 'Date', 'value' => $date],
        ];
        if ($reference) {
            $fields[] = ['label' => 'Reference', 'value' => $reference];
        }
        if ($method === 'cash_register') {
            $fields[] = ['label' => 'Cash from', 'value' => $context->location->name];
        }

        return new PreparedAction('Pay supplier bill', array_filter([
            'bill_id' => $bill->id,
            'amount' => $amount,
            'payment_method' => $method,
            'payment_date' => $date,
            'reference_no' => $reference,
        ], fn ($v) => $v !== null), $fields, 'Record payment');
    }

    /** The unpaid bill by id, else the supplier's only unpaid bill (several: ask which). */
    private function bill(array $input, ActionContext $context): SupplierBill
    {
        $bills = self::openBills($context);

        if (! empty($input['bill_id'])) {
            return $bills->firstWhere('id', (int) $input['bill_id'])
                ?? throw new ActionInputException('That bill is not an unpaid bill of this business. Use list_open_bills.');
        }

        $wanted = trim((string) ($input['supplier'] ?? ''));
        if ($wanted === '') {
            throw new ActionInputException('Say which bill is being paid: the supplier, or the bill from list_open_bills.');
        }
        $theirs = $bills->filter(fn (SupplierBill $b) => strcasecmp($b->supplier->name, $wanted) === 0)->values();

        return match ($theirs->count()) {
            1 => $theirs->first(),
            0 => throw new ActionInputException('There is no unpaid bill from "'.$wanted.'". Suppliers with unpaid bills: '
                .($bills->pluck('supplier.name')->unique()->sort()->implode(', ') ?: 'none').'.'),
            default => throw new ActionInputException($theirs->first()->supplier->name.' has '.$theirs->count().' unpaid bills: '
                .$theirs->map(fn (SupplierBill $b) => 'bill_id '.$b->id.($b->bill_number ? ' (no. '.$b->bill_number.')' : '')
                    .', '.$this->peso($b->remaining).' left, due '.$b->due_date->toDateString())->implode('; ')
                .'. Ask which one.'),
        };
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $bill = SupplierBill::query()->where('domain', $context->domain->name_slug)->with('supplier:id,name')->find($input['bill_id'] ?? null);
        if (! $bill) {
            return ActionResult::failed('This bill no longer exists.');
        }
        if ($input['payment_method'] === 'cash_register' && ! $context->location) {
            return ActionResult::failed('The store this was meant for no longer exists.');
        }
        if ($input['payment_method'] === 'cash_register' && $context->location->isWarehouse()) {
            return ActionResult::failed('Cash from the register needs a store, not a warehouse.');
        }

        return $this->call->run(
            'domains.finance.bill-payments.store',
            [
                'amount' => $input['amount'],
                'payment_method' => $input['payment_method'],
                'payment_date' => $input['payment_date'],
                'reference_no' => $input['reference_no'] ?? null,
                'location_id' => $input['payment_method'] === 'cash_register' ? $context->location->id : null,
            ],
            ['domain' => $context->domain, 'bill' => $bill],
            $context->user,
            'Recorded '.$this->peso($input['amount']).' paid to '.$bill->supplier->name.'.',
        );
    }
}
