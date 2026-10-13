<?php

namespace App\Services\Ai\Actions\Customers;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Adds a customer, like Customers → Add Customer. */
class CreateCustomer implements AssistantAction
{
    public const FIELDS = ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'address' => 'Address', 'date_of_birth' => 'Date of birth'];

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'create_customer';
    }

    public function permission(): string
    {
        return 'customers.store';
    }

    public function description(): string
    {
        return 'Propose adding a customer. The name is required; phone, email, address and date of birth are optional. Check with find_customer first that they are not already there.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Full name')->required(),
            'phone' => $schema->string()->description('Phone number (optional)'),
            'email' => $schema->string()->description('Email address (optional)'),
            'address' => $schema->string()->description('Address (optional)'),
            'date_of_birth' => $schema->string()->description('Date of birth, YYYY-MM-DD (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $data = self::normalise($input);
        if (($data['name'] ?? '') === '') {
            throw new ActionInputException('The customer\'s name is required.');
        }

        return new PreparedAction('Add customer', $data, self::describe($data), 'Add customer');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run('domains.customers.store', $input, ['domain' => $context->domain], $context->user, 'Added the customer "'.$input['name'].'".');
    }

    /** The given fields, trimmed, without empty ones. */
    public static function normalise(array $input): array
    {
        $data = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** @return list<array{label: string, value: string}> */
    public static function describe(array $data, array $before = []): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $label) {
            if (isset($data[$key])) {
                $old = isset($before[$key]) ? (string) $before[$key] : '';
                $fields[] = ['label' => $label, 'value' => $old !== '' && $old !== $data[$key] ? $old.' → '.$data[$key] : $data[$key]];
            }
        }

        return $fields;
    }
}
