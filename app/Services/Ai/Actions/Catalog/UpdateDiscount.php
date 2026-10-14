<?php

namespace App\Services\Ai\Actions\Catalog;

use App\Models\Product\Discount;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Changes a discount's name, value, scope, minimum or dates, like editing it under Products → Discounts. */
class UpdateDiscount implements AssistantAction
{
    public function __construct(
        private readonly ControllerCall $call,
        private readonly CreateDiscount $create,
    ) {}

    public function name(): string
    {
        return 'update_discount';
    }

    public function permission(): string
    {
        return 'products.discounts.update';
    }

    public function description(): string
    {
        return 'Propose changing a discount (discount_id from list_discounts). Send only what changes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'discount_id' => $schema->integer()->description('Discount id from list_discounts')->required(),
            ...CreateDiscount::fieldsSchema($schema, required: false),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $discount = $this->discount($input['discount_id'] ?? null, $context);
        $changes = CreateDiscount::normalise($input, required: false);
        if ($changes === []) {
            throw new ActionInputException('Say what to change on the discount.');
        }

        // Check the result as a whole (e.g. a percentage over 100, an end date before the start).
        CreateDiscount::normalise([...$this->current($discount), ...$changes], required: true);

        return new PreparedAction('Change discount', ['discount_id' => $discount->id, 'changes' => $changes], [
            ['label' => 'Discount', 'value' => $discount->name],
            ...$this->create->describe($changes, $this->current($discount)),
        ], 'Save changes');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $discount = Discount::query()->forDomain($context->domain->name_slug)->find($input['discount_id']);
        if (! $discount) {
            return ActionResult::failed('That discount no longer exists.');
        }

        return $this->call->run(
            'domains.products.discounts.update',
            array_filter([...$this->current($discount), ...$input['changes']], fn ($v) => $v !== null),
            ['domain' => $context->domain, 'discount' => $discount],
            $context->user,
            'Updated the discount "'.($input['changes']['name'] ?? $discount->name).'".',
        );
    }

    private function discount(mixed $id, ActionContext $context): Discount
    {
        $discount = $id ? Discount::query()->forDomain($context->domain->name_slug)->find($id) : null;
        if (! $discount) {
            throw new ActionInputException('No such discount in this business. Use list_discounts to get its id.');
        }

        return $discount;
    }

    /** The edit form sends every field, so start from what the discount has now. */
    private function current(Discount $discount): array
    {
        return [
            'name' => $discount->name,
            'type' => $discount->type === 'percent' ? 'percentage' : $discount->type,
            'value' => $discount->value !== null ? (float) $discount->value : null,
            'scope' => $discount->scope,
            'min_order_amount' => $discount->min_order_amount !== null ? (float) $discount->min_order_amount : null,
            'start_date' => $discount->start_date ? Carbon::parse($discount->start_date)->toDateString() : null,
            'end_date' => $discount->end_date ? Carbon::parse($discount->end_date)->toDateString() : null,
        ];
    }
}
