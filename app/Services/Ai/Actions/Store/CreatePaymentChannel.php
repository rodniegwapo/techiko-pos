<?php

namespace App\Services\Ai\Actions\Store;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Adds a payment channel (card type, e-wallet like GCash, or bank) for the store, like Cash & wallet → Payment channels. */
class CreatePaymentChannel implements AssistantAction
{
    private const KINDS = ['card' => 'Card', 'ewallet' => 'E-wallet', 'bank' => 'Bank'];

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'create_payment_channel';
    }

    public function permission(): string
    {
        return 'payment-card-types.store';
    }

    public function description(): string
    {
        return 'Propose adding a payment channel for the store the user works in: a card type (e.g. Visa), an e-wallet (e.g. GCash, Maya) or a bank (e.g. BDO).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name, e.g. GCash, Maya, BDO, Visa')->required(),
            'kind' => $schema->string()->enum(array_keys(self::KINDS))->description('card, ewallet or bank')->required(),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $name = trim((string) ($input['name'] ?? ''));
        $kind = $input['kind'] ?? null;
        if ($name === '' || ! isset(self::KINDS[$kind])) {
            throw new ActionInputException('Give the channel\'s name and whether it is a card, ewallet or bank.');
        }
        if (! $context->location) {
            throw new ActionInputException('No store is selected. The user should pick a store first.');
        }

        return new PreparedAction('Add payment channel', ['name' => $name, 'kind' => $kind], [
            ['label' => 'Name', 'value' => $name],
            ['label' => 'Kind', 'value' => self::KINDS[$kind]],
            ['label' => 'Store', 'value' => $context->location->name],
        ], 'Add channel');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        if (! $context->location) {
            return ActionResult::failed('The store this was meant for no longer exists.');
        }

        return $this->call->run(
            'domains.payment-card-types.store',
            [...$input, 'location_id' => $context->location->id],
            ['domain' => $context->domain],
            $context->user,
            'Added the payment channel "'.$input['name'].'" to '.$context->location->name.'.',
        );
    }
}
