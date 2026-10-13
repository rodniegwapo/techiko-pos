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

/** Adds or deducts a customer's loyalty points, like Loyalty Program → Adjust points. */
class AdjustLoyaltyPoints implements AssistantAction
{
    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'adjust_loyalty_points';
    }

    public function permission(): string
    {
        return 'loyalty.adjust-points';
    }

    public function description(): string
    {
        return 'Propose adding or deducting loyalty points for a customer (customer_id from find_customer), with a reason.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->integer()->description('Customer id from find_customer')->required(),
            'type' => $schema->string()->enum(['add', 'deduct'])->description('add or deduct')->required(),
            'points' => $schema->integer()->description('How many points, more than 0')->required(),
            'reason' => $schema->string()->description('Why (e.g. "birthday bonus", "correction")')->required(),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $customer = CustomerLookup::find($input['customer_id'] ?? null, $context);
        $type = $input['type'] ?? null;
        $points = (int) ($input['points'] ?? 0);
        $reason = trim((string) ($input['reason'] ?? ''));

        if (! in_array($type, ['add', 'deduct'], true) || $points <= 0) {
            throw new ActionInputException('Say whether to add or deduct, and how many points (more than 0).');
        }
        if ($reason === '') {
            throw new ActionInputException('Give a reason for the adjustment.');
        }

        $current = (int) $customer->loyalty_points;
        $new = $type === 'add' ? $current + $points : max(0, $current - $points);

        return new PreparedAction($type === 'add' ? 'Add loyalty points' : 'Deduct loyalty points', [
            'customer_id' => $customer->id, 'type' => $type, 'points' => $points, 'reason' => $reason,
        ], [
            ['label' => 'Customer', 'value' => $customer->name],
            ['label' => $type === 'add' ? 'Add' : 'Deduct', 'value' => number_format($points).' points'],
            ['label' => 'Points', 'value' => number_format($current).' → '.number_format($new)],
            ['label' => 'Reason', 'value' => $reason],
        ], $type === 'add' ? 'Add points' : 'Deduct points');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $customer = Customer::query()->forDomain($context->domain->name_slug)->find($input['customer_id']);
        if (! $customer) {
            return ActionResult::failed('That customer no longer exists.');
        }

        return $this->call->run(
            'domains.loyalty.adjust-points',
            ['type' => $input['type'] === 'add' ? 'add' : 'subtract', 'amount' => $input['points'], 'reason' => $input['reason']],
            ['domain' => $context->domain, 'customer' => $customer],
            $context->user,
            ($input['type'] === 'add' ? 'Added ' : 'Deducted ').number_format($input['points']).' points '.($input['type'] === 'add' ? 'to ' : 'from ').$customer->name.'.',
        );
    }
}
