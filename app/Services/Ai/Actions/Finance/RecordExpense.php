<?php

namespace App\Services\Ai\Actions\Finance;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\HasInputTemplate;
use App\Services\Ai\Actions\PreparedAction;
use App\Support\FinanceAccess;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;

/** Records an expense (rent, electricity, salaries…), like Finance → Expenses → add. */
class RecordExpense implements AssistantAction, HasInputTemplate
{
    use DescribesValues;

    public const METHODS = ['cash_register' => 'Cash from the register', 'bank' => 'Bank', 'ewallet' => 'E-wallet', 'card' => 'Card', 'other' => 'Other'];

    public function __construct(private readonly ControllerCall $call) {}

    public function templateTitle(): string
    {
        return 'Record expense';
    }

    public function templateGuide(): ?string
    {
        return 'record-expense';
    }

    public function template(ActionContext $context): array
    {
        if (! FinanceAccess::canManage($context->user)) {
            throw new ActionInputException('Only managers and above can record expenses.');
        }

        return [
            ['label' => 'Category', 'required' => true, 'hint' => $this->namesHint($this->categories($context)->pluck('name'))],
            ['label' => 'Amount', 'required' => true, 'hint' => '₱'],
            ['label' => 'What it was for', 'required' => true, 'hint' => null],
            ['label' => 'Paid with', 'required' => true, 'hint' => $this->namesHint(self::METHODS)],
            ['label' => 'Date', 'required' => false, 'hint' => 'YYYY-MM-DD'],
            ['label' => 'Paid to', 'required' => false, 'hint' => null],
            ['label' => 'Reference no.', 'required' => false, 'hint' => null],
        ];
    }

    /** @return Collection<int, ExpenseCategory> This business's active expense categories, A–Z. */
    private function categories(ActionContext $context): Collection
    {
        return ExpenseCategory::query()->forDomain($context->domain->name_slug)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function name(): string
    {
        return 'record_expense';
    }

    public function permission(): string
    {
        return 'expenses.store';
    }

    public function description(): string
    {
        return 'Propose recording an expense for the store the user works in. Give the category by name (expense_category) or id (expense_category_id from list_expense_categories). Payment methods: '
            .collect(self::METHODS)->map(fn ($label, $key) => $key.' = '.$label)->implode(', ').'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_category_id' => $schema->integer()->description('Category id from list_expense_categories (or give expense_category)'),
            'expense_category' => $schema->string()->description('Category name, as the user wrote it (used when there is no expense_category_id)'),
            'amount' => $schema->number()->description('Amount in pesos')->required(),
            'description' => $schema->string()->description('What it was for, e.g. "October electricity"')->required(),
            'payment_method' => $schema->string()->enum(array_keys(self::METHODS))->description('How it was paid')->required(),
            'expense_date' => $schema->string()->description('Date paid, YYYY-MM-DD (default today; not in the future)'),
            'payee' => $schema->string()->description('Who was paid (optional)'),
            'reference_no' => $schema->string()->description('Receipt or reference number (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        if (! FinanceAccess::canManage($context->user)) {
            throw new ActionInputException('Only managers and above can record expenses.');
        }
        $categories = $this->categories($context);
        $wanted = trim((string) ($input['expense_category'] ?? ''));
        if (! empty($input['expense_category_id'])) {
            $category = $categories->firstWhere('id', (int) $input['expense_category_id']);
        } elseif ($wanted !== '') {
            $category = $categories->first(fn (ExpenseCategory $c) => strcasecmp($c->name, $wanted) === 0);
            if (! $category) {
                throw new ActionInputException('There is no expense category "'.$wanted.'". The categories are: '
                    .($categories->pluck('name')->implode(', ') ?: 'none yet').'.');
            }
        } else {
            $category = null;
        }
        if (! $category) {
            throw new ActionInputException('Choose an expense category from list_expense_categories.');
        }
        $amount = $input['amount'] ?? null;
        if (! is_numeric($amount) || $amount <= 0) {
            throw new ActionInputException('The amount is required and must be more than 0.');
        }
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            throw new ActionInputException('Say what the expense was for.');
        }
        $method = $input['payment_method'] ?? null;
        if (! isset(self::METHODS[$method]) || ! in_array($method, Expense::PAYMENT_METHODS, true)) {
            throw new ActionInputException('Say how it was paid: cash_register, bank, ewallet, card or other.');
        }
        try {
            $date = Carbon::parse($input['expense_date'] ?? today()->toDateString())->toDateString();
        } catch (\Throwable) {
            throw new ActionInputException('The date must be YYYY-MM-DD.');
        }
        if ($date > today()->toDateString()) {
            throw new ActionInputException('An expense can\'t be dated in the future.');
        }

        $data = array_filter([
            'expense_category_id' => $category->id,
            'amount' => round((float) $amount, 2),
            'description' => $description,
            'payment_method' => $method,
            'expense_date' => $date,
            'payee' => trim((string) ($input['payee'] ?? '')) ?: null,
            'reference_no' => trim((string) ($input['reference_no'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        $fields = [
            ['label' => 'Amount', 'value' => $this->peso($data['amount'])],
            ['label' => 'For', 'value' => $description],
            ['label' => 'Category', 'value' => $category->name],
            ['label' => 'Paid with', 'value' => self::METHODS[$method]],
            ['label' => 'Date', 'value' => $date],
        ];
        foreach (['payee' => 'Paid to', 'reference_no' => 'Reference'] as $key => $label) {
            if (isset($data[$key])) {
                $fields[] = ['label' => $label, 'value' => $data[$key]];
            }
        }
        if ($context->location) {
            $fields[] = ['label' => 'Store', 'value' => $context->location->name];
        }

        return new PreparedAction('Record expense', $data, $fields, 'Record expense');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run(
            'domains.expenses.store',
            [...$input, 'location_id' => $context->location?->id],
            ['domain' => $context->domain],
            $context->user,
            'Recorded the expense of '.$this->peso($input['amount']).' ('.$input['description'].').',
        );
    }
}
