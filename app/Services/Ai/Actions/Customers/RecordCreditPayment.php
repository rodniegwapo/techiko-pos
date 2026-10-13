<?php

namespace App\Services\Ai\Actions\Customers;

use App\Models\Customer;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Records a payment on a customer's credit (utang), like Credit Management → Record payment. */
class RecordCreditPayment implements AssistantAction
{
    use DescribesValues;

    private const METHODS = ['cash' => 'Cash', 'card' => 'Card', 'e-wallet' => 'E-Wallet', 'bank' => 'Bank'];

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'record_credit_payment';
    }

    public function permission(): string
    {
        return 'credits.transactions.store';
    }

    public function description(): string
    {
        return 'Propose recording a payment a customer made on their credit (utang). customer_id from find_customer; the payment is applied to their oldest unpaid credit.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->integer()->description('Customer id from find_customer')->required(),
            'amount' => $schema->number()->description('Amount paid in pesos')->required(),
            'payment_method' => $schema->string()->enum(array_keys(self::METHODS))->description('How they paid (default cash)'),
            'reference_number' => $schema->string()->description('Reference number, e.g. for GCash (optional)'),
            'notes' => $schema->string()->description('Notes (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $customer = CustomerLookup::find($input['customer_id'] ?? null, $context);
        $amount = $input['amount'] ?? null;
        if (! is_numeric($amount) || $amount <= 0) {
            throw new ActionInputException('The amount paid is required and must be more than 0.');
        }
        $balance = (float) $customer->credit_balance;
        if ($balance <= 0) {
            throw new ActionInputException($customer->name.' has no credit balance to pay.');
        }
        if ((float) $amount > $balance) {
            throw new ActionInputException('The payment is more than '.$customer->name.' owes ('.$this->peso($balance).').');
        }
        $method = $input['payment_method'] ?? 'cash';
        if (! isset(self::METHODS[$method])) {
            throw new ActionInputException('The payment method must be cash, card, e-wallet or bank.');
        }

        $data = array_filter([
            'customer_id' => $customer->id,
            'amount' => round((float) $amount, 2),
            'payment_method' => $method,
            'reference_number' => trim((string) ($input['reference_number'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        $fields = [
            ['label' => 'Customer', 'value' => $customer->name],
            ['label' => 'Amount paid', 'value' => $this->peso($data['amount'])],
            ['label' => 'Paid by', 'value' => self::METHODS[$method]],
            ['label' => 'Balance owed', 'value' => $this->peso($balance).' → '.$this->peso(max(0, $balance - $data['amount']))],
        ];
        foreach (['reference_number' => 'Reference', 'notes' => 'Notes'] as $key => $label) {
            if (isset($data[$key])) {
                $fields[] = ['label' => $label, 'value' => $data[$key]];
            }
        }

        return new PreparedAction('Record credit payment', $data, $fields, 'Record payment');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $customer = Customer::query()->forDomain($context->domain->name_slug)->find($input['customer_id']);
        if (! $customer) {
            return ActionResult::failed('That customer no longer exists.');
        }

        return $this->call->run(
            'domains.credits.transactions.store',
            ['transaction_type' => 'payment', ...array_diff_key($input, ['customer_id' => true])],
            ['domain' => $context->domain, 'customer' => $customer],
            $context->user,
            'Recorded '.$this->peso($input['amount']).' paid by '.$customer->name.'.',
        );
    }
}
