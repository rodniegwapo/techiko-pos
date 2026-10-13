<?php

namespace App\Services\Ai\Actions\Catalog;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\PreparedAction;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Adds a discount for the POS, like Products → Discounts → add. */
class CreateDiscount implements AssistantAction
{
    use DescribesValues;

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'create_discount';
    }

    public function permission(): string
    {
        return 'products.discounts.store';
    }

    public function description(): string
    {
        return 'Propose adding a discount cashiers can apply at the POS: a percentage or a peso amount, for a whole order or for an item, optionally with a minimum order and start/end dates.';
    }

    public function schema(JsonSchema $schema): array
    {
        return self::fieldsSchema($schema, required: true);
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $data = self::normalise($input, required: true);

        return new PreparedAction('Add discount', $data, $this->describe($data), 'Add discount');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run('domains.products.discounts.store', $input, ['domain' => $context->domain], $context->user, 'Added the discount "'.$input['name'].'".');
    }

    public static function fieldsSchema(JsonSchema $schema, bool $required): array
    {
        $req = fn ($type) => $required ? $type->required() : $type;

        return [
            'name' => $req($schema->string()->description('Discount name, e.g. "Payday 10%"')),
            'type' => $req($schema->string()->enum(['percentage', 'amount'])->description('percentage or amount (pesos off)')),
            'value' => $req($schema->number()->description('The percent (0–100) or the pesos off')),
            'scope' => $req($schema->string()->enum(['order', 'product'])->description('order = whole order, product = one item')),
            'min_order_amount' => $schema->number()->description('Minimum order total in pesos (optional)'),
            'start_date' => $schema->string()->description('Start, YYYY-MM-DD (optional; needs an end date)'),
            'end_date' => $schema->string()->description('End, YYYY-MM-DD (optional)'),
        ];
    }

    /** Checks the values the discount page also checks, so the card only shows ones that will save. */
    public static function normalise(array $input, bool $required): array
    {
        $data = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name !== '') {
            $data['name'] = $name;
        }
        if (isset($input['type']) && $input['type'] !== '') {
            if (! in_array($input['type'], ['percentage', 'amount'], true)) {
                throw new ActionInputException('The type must be percentage or amount.');
            }
            $data['type'] = $input['type'];
        }
        if (isset($input['value']) && $input['value'] !== '') {
            if (! is_numeric($input['value']) || $input['value'] < 0) {
                throw new ActionInputException('The discount value must be a number (0 or more).');
            }
            $data['value'] = round((float) $input['value'], 2);
        }
        if (isset($input['scope']) && $input['scope'] !== '') {
            if (! in_array($input['scope'], ['order', 'product'], true)) {
                throw new ActionInputException('The discount applies to the whole order or to an item (order or product).');
            }
            $data['scope'] = $input['scope'];
        }
        if (isset($input['min_order_amount']) && $input['min_order_amount'] !== '') {
            $data['min_order_amount'] = round((float) $input['min_order_amount'], 2);
        }
        foreach (['start_date', 'end_date'] as $key) {
            if (! empty($input[$key])) {
                try {
                    $data[$key] = Carbon::parse($input[$key])->toDateString();
                } catch (\Throwable) {
                    throw new ActionInputException('Dates must be YYYY-MM-DD.');
                }
            }
        }

        if ($required && (! isset($data['name'], $data['type'], $data['value'], $data['scope']))) {
            throw new ActionInputException('A discount needs a name, a type (percentage or amount), a value and whether it is for the whole order or an item.');
        }
        if (($data['type'] ?? null) === 'percentage' && ($data['value'] ?? 0) > 100) {
            throw new ActionInputException('A percentage discount can\'t be more than 100%.');
        }
        if (isset($data['start_date']) && ! isset($data['end_date'])) {
            throw new ActionInputException('A discount with a start date also needs an end date.');
        }
        if (isset($data['start_date'], $data['end_date']) && $data['end_date'] <= $data['start_date']) {
            throw new ActionInputException('The end date must be after the start date.');
        }

        return $data;
    }

    /** @return list<array{label: string, value: string}> */
    public function describe(array $data, array $before = []): array
    {
        $show = fn (string $key, mixed $value) => match ($key) {
            'type' => $value === 'percentage' ? 'Percentage' : 'Peso amount',
            'value' => ($data['type'] ?? $before['type'] ?? null) === 'percentage' ? rtrim(rtrim(number_format((float) $value, 2), '0'), '.').'%' : $this->peso($value),
            'scope' => $value === 'order' ? 'Whole order' : 'One item',
            'min_order_amount' => $this->peso($value),
            default => (string) $value,
        };
        $labels = ['name' => 'Name', 'type' => 'Type', 'value' => 'Discount', 'scope' => 'Applies to', 'min_order_amount' => 'Minimum order', 'start_date' => 'Starts', 'end_date' => 'Ends'];

        $fields = [];
        foreach ($labels as $key => $label) {
            if (isset($data[$key])) {
                $old = array_key_exists($key, $before) && $before[$key] !== null ? $show($key, $before[$key]) : null;
                $fields[] = ['label' => $label, 'value' => $this->change($old, $show($key, $data[$key]))];
            }
        }

        return $fields;
    }
}
