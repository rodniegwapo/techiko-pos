<?php

namespace App\Services\Ai\Actions\Inventory;

use App\Models\Product\Product;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Adds delivered stock to the store the user works in, like Inventory → Products → Receive stock.
 * Stock goes up as soon as it is confirmed.
 */
class ReceiveStock implements AssistantAction
{
    use DescribesValues;

    private const MAX_ITEMS = 20;

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'receive_stock';
    }

    public function permission(): string
    {
        return 'inventory.receive';
    }

    public function description(): string
    {
        return 'Propose receiving stock (a delivery) into the store the user works in: for each product, how many arrived and what each one cost. Give the product by id (from find_products or stock_levels) or by its exact name, SKU or barcode. The unit cost defaults to the product\'s cost; ask for it when the product has none. Stock goes up when the user confirms.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('Product id (or give product)'),
                'product' => $schema->string()->description('Exact product name, SKU or barcode (used when there is no product_id)'),
                'quantity' => $schema->integer()->description('How many arrived (1 or more)')->required(),
                'unit_cost' => $schema->number()->description('What each one cost, in pesos (default: the product\'s cost)'),
            ]))->description('The products delivered')->required(),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        if (! $context->location) {
            throw new ActionInputException('No store is selected. The user should pick a store first.');
        }
        $items = array_values(array_filter((array) ($input['items'] ?? []), 'is_array'));
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw new ActionInputException('List between 1 and '.self::MAX_ITEMS.' products with how many arrived.');
        }

        $lines = [];
        $fields = [['label' => 'Store', 'value' => $context->location->name]];
        $total = 0.0;
        foreach ($items as $item) {
            $product = $this->product($item, $context);
            $quantity = $item['quantity'] ?? null;
            if (! is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity < 1) {
                throw new ActionInputException('How many '.$product->name.' arrived? It must be a whole number, 1 or more.');
            }
            $cost = $item['unit_cost'] ?? $product->cost;
            if (! is_numeric($cost) || $cost < 0) {
                throw new ActionInputException('What did each '.$product->name.' cost? It has no cost saved.');
            }

            $quantity = (int) $quantity;
            $cost = round((float) $cost, 2);
            $lines[] = ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost];
            $total += $quantity * $cost;
            $fields[] = ['label' => $product->name, 'value' => $quantity.' × '.$this->peso($cost).' = '.$this->peso($quantity * $cost)];
        }
        $fields[] = ['label' => 'Total cost', 'value' => $this->peso($total)];

        return new PreparedAction('Receive stock', ['items' => $lines], $fields, 'Receive stock');
    }

    /** The product by id, else by exact name, SKU or barcode (any letter case), in this business only. */
    private function product(array $item, ActionContext $context): Product
    {
        $products = Product::query()->forDomain($context->domain->name_slug);

        if (! empty($item['product_id'])) {
            return $products->find((int) $item['product_id'])
                ?? throw new ActionInputException('One of the products is not in this business. Use find_products to get the ids.');
        }

        $wanted = trim((string) ($item['product'] ?? ''));
        if ($wanted === '') {
            throw new ActionInputException('Say which product each line is for.');
        }
        $matches = $products->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($wanted)])
            ->orWhere('SKU', $wanted)->orWhere('barcode', $wanted))
            ->limit(5)->get(['id', 'name', 'cost']);

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => throw new ActionInputException('There is no product "'.$wanted.'". Use find_products to look it up.'),
            default => throw new ActionInputException('Several products match "'.$wanted.'": '.$matches->pluck('name')->implode(', ').'. Ask which one.'),
        };
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        if (! $context->location) {
            return ActionResult::failed('The store this was meant for no longer exists.');
        }

        $count = array_sum(array_column($input['items'], 'quantity'));

        return $this->call->run(
            'domains.inventory.receive',
            ['location_id' => $context->location->id, 'items' => $input['items']],
            ['domain' => $context->domain],
            $context->user,
            'Received '.$count.' '.($count === 1 ? 'item' : 'items').' into '.$context->location->name.'.',
        );
    }
}
