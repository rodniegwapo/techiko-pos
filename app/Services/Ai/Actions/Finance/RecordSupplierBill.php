<?php

namespace App\Services\Ai\Actions\Finance;

use App\Models\Finance\Supplier;
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

/** Records a bill from a supplier (money owed to them), like Finance → Bills to pay → add bill. */
class RecordSupplierBill implements AssistantAction, HasInputTemplate
{
    use DescribesValues;

    public function __construct(private readonly ControllerCall $call) {}

    public function templateTitle(): string
    {
        return 'Record supplier bill';
    }

    public function templateGuide(): ?string
    {
        return 'supplier-bill';
    }

    public function template(ActionContext $context): array
    {
        $suppliers = $this->suppliers($context)->where('is_active', true)->pluck('name');
        if ($suppliers->isEmpty()) {
            throw new ActionInputException('There are no suppliers yet. Add the supplier first.');
        }
        $categories = $this->namesHint(ExpenseCategories::active($context)->pluck('name'));

        return [
            ['label' => 'Supplier', 'required' => true, 'hint' => $this->namesHint($suppliers)],
            ['label' => 'Amount', 'required' => true, 'hint' => '₱'],
            ['label' => 'Bill type', 'required' => true, 'hint' => 'inventory or expense'],
            ['label' => 'Expense category', 'required' => false, 'hint' => ($categories ? $categories.'; ' : '').'only for expense bills'],
            ['label' => 'Bill date', 'required' => false, 'hint' => 'YYYY-MM-DD'],
            ['label' => 'Due date', 'required' => false, 'hint' => 'YYYY-MM-DD'],
            ['label' => 'Bill no.', 'required' => false, 'hint' => null],
        ];
    }

    /** @return Collection<int, Supplier> This business's suppliers, A–Z. */
    private function suppliers(ActionContext $context): Collection
    {
        return Supplier::query()->where('domain', $context->domain->name_slug)->orderBy('name')->get(['id', 'name', 'is_active', 'payment_terms_days']);
    }

    public function name(): string
    {
        return 'record_supplier_bill';
    }

    public function permission(): string
    {
        return 'finance.bills.store';
    }

    public function description(): string
    {
        return 'Propose recording a supplier bill (money the business owes a supplier). Give the supplier by name (supplier) or id (supplier_id from list_suppliers). bill_type "inventory" is for stock bought; "expense" is for a service or cost and needs an expense category, by name (expense_category) or id (expense_category_id).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_id' => $schema->integer()->description('Supplier id from list_suppliers (or give supplier)'),
            'supplier' => $schema->string()->description('Supplier name, as the user wrote it (used when there is no supplier_id)'),
            'amount' => $schema->number()->description('Bill amount in pesos')->required(),
            'bill_type' => $schema->string()->enum(['inventory', 'expense'])->description('inventory (stock bought) or expense')->required(),
            'expense_category_id' => $schema->integer()->description('Needed for an expense bill: id from list_expense_categories (or give expense_category)'),
            'expense_category' => $schema->string()->description('Expense category name, as the user wrote it (used when there is no expense_category_id)'),
            'bill_date' => $schema->string()->description('Bill date, YYYY-MM-DD (default today)'),
            'due_date' => $schema->string()->description('Due date, YYYY-MM-DD (default: the supplier\'s payment terms)'),
            'bill_number' => $schema->string()->description('The supplier\'s bill or invoice number (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $suppliers = $this->suppliers($context);
        $wanted = trim((string) ($input['supplier'] ?? ''));
        if (empty($input['supplier_id']) && $wanted !== '') {
            $supplier = $suppliers->first(fn (Supplier $s) => strcasecmp($s->name, $wanted) === 0)
                ?? throw new ActionInputException('There is no supplier "'.$wanted.'". The suppliers are: '
                    .($suppliers->pluck('name')->implode(', ') ?: 'none yet; add the supplier first').'.');
        } else {
            $supplier = $suppliers->firstWhere('id', (int) ($input['supplier_id'] ?? 0));
        }
        if (! $supplier) {
            throw new ActionInputException('No such supplier in this business. Use list_suppliers, or add the supplier first.');
        }
        $amount = $input['amount'] ?? null;
        if (! is_numeric($amount) || $amount <= 0) {
            throw new ActionInputException('The bill amount is required and must be more than 0.');
        }
        $type = $input['bill_type'] ?? null;
        if (! in_array($type, ['inventory', 'expense'], true)) {
            throw new ActionInputException('Say whether the bill is for inventory (stock bought) or an expense.');
        }
        $category = null;
        if ($type === 'expense') {
            $category = ExpenseCategories::resolve($input, $context);
            if (! $category) {
                throw new ActionInputException('An expense bill needs an expense category from list_expense_categories.');
            }
        }
        try {
            $billDate = Carbon::parse($input['bill_date'] ?? today()->toDateString())->toDateString();
            $dueDate = isset($input['due_date']) && $input['due_date'] !== ''
                ? Carbon::parse($input['due_date'])->toDateString()
                : Carbon::parse($billDate)->addDays($supplier->payment_terms_days ?? 30)->toDateString();
        } catch (\Throwable) {
            throw new ActionInputException('Dates must be YYYY-MM-DD.');
        }
        if ($dueDate < $billDate) {
            throw new ActionInputException('The due date can\'t be before the bill date.');
        }

        $data = array_filter([
            'supplier_id' => $supplier->id,
            'amount' => round((float) $amount, 2),
            'bill_type' => $type,
            'expense_category_id' => $category?->id,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'bill_number' => trim((string) ($input['bill_number'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        $fields = [
            ['label' => 'Supplier', 'value' => $supplier->name],
            ['label' => 'Amount', 'value' => $this->peso($data['amount'])],
            ['label' => 'For', 'value' => $type === 'inventory' ? 'Stock bought' : 'Expense: '.$category->name],
            ['label' => 'Bill date', 'value' => $billDate],
            ['label' => 'Due', 'value' => $dueDate],
        ];
        if (isset($data['bill_number'])) {
            $fields[] = ['label' => 'Bill no.', 'value' => $data['bill_number']];
        }

        return new PreparedAction('Record supplier bill', $data, $fields, 'Record bill');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run(
            'domains.finance.bills.store',
            [...$input, 'location_id' => $context->location?->id],
            ['domain' => $context->domain],
            $context->user,
            'Recorded the bill of '.$this->peso($input['amount']).'.',
        );
    }
}
