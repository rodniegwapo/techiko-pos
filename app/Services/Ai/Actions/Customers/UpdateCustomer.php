<?php

namespace App\Services\Ai\Actions\Customers;

use App\Models\Customer;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Changes a customer's name or contact details, like Customers → Edit Customer. */
class UpdateCustomer implements AssistantAction
{
    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'update_customer';
    }

    public function permission(): string
    {
        return 'customers.update';
    }

    public function description(): string
    {
        return 'Propose changing a customer\'s name, phone, email, address or date of birth (customer_id from find_customer). Send only what changes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->integer()->description('Customer id from find_customer')->required(),
            'name' => $schema->string()->description('New full name'),
            'phone' => $schema->string()->description('New phone number'),
            'email' => $schema->string()->description('New email address'),
            'address' => $schema->string()->description('New address'),
            'date_of_birth' => $schema->string()->description('New date of birth, YYYY-MM-DD'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $customer = CustomerLookup::find($input['customer_id'] ?? null, $context);
        $changes = CreateCustomer::normalise($input);
        if ($changes === []) {
            throw new ActionInputException('Say what to change on the customer.');
        }

        $before = [...$customer->only(['name', 'phone', 'email', 'address']), 'date_of_birth' => $customer->date_of_birth?->toDateString()];

        return new PreparedAction('Change customer', ['customer_id' => $customer->id, 'changes' => $changes], [
            ['label' => 'Customer', 'value' => $customer->name],
            ...CreateCustomer::describe($changes, $before),
        ], 'Save changes');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $customer = Customer::query()->forDomain($context->domain->name_slug)->find($input['customer_id']);
        if (! $customer) {
            return ActionResult::failed('That customer no longer exists.');
        }

        // The edit form sends every field, so start from what the customer has now.
        $current = [...$customer->only(['name', 'phone', 'email', 'address']), 'date_of_birth' => $customer->date_of_birth?->toDateString()];

        return $this->call->run(
            'domains.customers.update',
            array_filter([...$current, ...$input['changes']], fn ($v) => $v !== null && $v !== ''),
            ['domain' => $context->domain, 'customer' => $customer],
            $context->user,
            'Updated the customer "'.($input['changes']['name'] ?? $customer->name).'".',
        );
    }
}
