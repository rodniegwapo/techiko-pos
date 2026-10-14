<?php

namespace App\Services\Ai\Actions\Finance;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\HasInputTemplate;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Adds a supplier, like Finance → Supplier bills → add supplier. */
class CreateSupplier implements AssistantAction, HasInputTemplate
{
    public function __construct(private readonly ControllerCall $call) {}

    public function templateTitle(): string
    {
        return 'Add supplier';
    }

    public function templateGuide(): ?string
    {
        return 'add-supplier';
    }

    public function template(ActionContext $context): array
    {
        return [
            ['label' => 'Name', 'required' => true, 'hint' => null],
            ['label' => 'Pays bills within', 'required' => false, 'hint' => 'days'],
            ['label' => 'Contact person', 'required' => false, 'hint' => null],
            ['label' => 'Phone', 'required' => false, 'hint' => null],
            ['label' => 'Email', 'required' => false, 'hint' => null],
        ];
    }

    public function name(): string
    {
        return 'create_supplier';
    }

    public function permission(): string
    {
        return 'finance.suppliers.store';
    }

    public function description(): string
    {
        return 'Propose adding a supplier: name, and how many days the business usually has to pay their bills (payment terms).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Supplier name')->required(),
            'payment_terms_days' => $schema->integer()->description('Days to pay their bills, 0 to 365 (default 30)'),
            'contact_person' => $schema->string()->description('Contact person (optional)'),
            'phone' => $schema->string()->description('Phone (optional)'),
            'email' => $schema->string()->description('Email (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ActionInputException('The supplier\'s name is required.');
        }
        $terms = (int) ($input['payment_terms_days'] ?? 30);
        if ($terms < 0 || $terms > 365) {
            throw new ActionInputException('Payment terms must be 0 to 365 days.');
        }

        $data = array_filter([
            'name' => $name,
            'payment_terms_days' => $terms,
            'contact_person' => trim((string) ($input['contact_person'] ?? '')) ?: null,
            'phone' => trim((string) ($input['phone'] ?? '')) ?: null,
            'email' => trim((string) ($input['email'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        $fields = [['label' => 'Name', 'value' => $name], ['label' => 'Payment terms', 'value' => $terms.' days']];
        foreach (['contact_person' => 'Contact', 'phone' => 'Phone', 'email' => 'Email'] as $key => $label) {
            if (isset($data[$key])) {
                $fields[] = ['label' => $label, 'value' => $data[$key]];
            }
        }

        return new PreparedAction('Add supplier', $data, $fields, 'Add supplier');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run('domains.finance.suppliers.store', [...$input, 'is_active' => true], ['domain' => $context->domain], $context->user, 'Added the supplier "'.$input['name'].'".');
    }
}
