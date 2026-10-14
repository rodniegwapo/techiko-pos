<?php

namespace App\Services\Ai\Actions\Inventory;

use App\Http\Controllers\StockAdjustmentController;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Prepares a stock adjustment (damaged, expired, lost, recount…) for the store the user works in,
 * like Inventory → Stock Adjustments → New Adjustment. It is saved as a draft: stock only changes
 * once someone submits it and a manager approves it on the page.
 */
class CreateStockAdjustment implements AssistantAction
{
    private const MAX_ITEMS = 20;

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'create_stock_adjustment';
    }

    public function permission(): string
    {
        return 'inventory.adjustments.store';
    }

    public function description(): string
    {
        return 'Propose a stock adjustment draft for the store the user works in: for each product (id from stock_levels or find_products), the quantity actually there now (actual_quantity), plus the reason. It does not change stock until it is submitted and approved on the Stock Adjustments page.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->enum(array_keys(StockAdjustmentController::adjustmentReasons()))->description('Why the stock is wrong')->required(),
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('Product id')->required(),
                'actual_quantity' => $schema->integer()->description('How many are actually there now (0 or more)')->required(),
            ]))->description('The products and their actual quantities')->required(),
            'description' => $schema->string()->description('Notes about the adjustment (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $reasons = StockAdjustmentController::adjustmentReasons();
        $reason = $input['reason'] ?? null;
        if (! isset($reasons[$reason])) {
            throw new ActionInputException('Choose a reason: '.implode(', ', array_keys($reasons)).'.');
        }
        if (! $context->location) {
            throw new ActionInputException('No store is selected. The user should pick a store first.');
        }
        $items = array_values(array_filter((array) ($input['items'] ?? []), 'is_array'));
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw new ActionInputException('List between 1 and '.self::MAX_ITEMS.' products with their actual quantities.');
        }

        $products = Product::query()->forDomain($context->domain->name_slug)
            ->whereIn('id', array_column($items, 'product_id'))
            ->get(['id', 'name'])->keyBy('id');
        $onHand = ProductInventory::query()
            ->where('location_id', $context->location->id)
            ->whereIn('product_id', $products->keys())
            ->pluck('quantity_on_hand', 'product_id');

        $lines = [];
        $fields = [
            ['label' => 'Store', 'value' => $context->location->name],
            ['label' => 'Reason', 'value' => $reasons[$reason]],
        ];
        foreach ($items as $item) {
            $product = $products[$item['product_id'] ?? 0] ?? null;
            $actual = $item['actual_quantity'] ?? null;
            if (! $product) {
                throw new ActionInputException('One of the products is not in this business. Use stock_levels or find_products to get the ids.');
            }
            if (! is_numeric($actual) || (int) $actual < 0) {
                throw new ActionInputException('The actual quantity of '.$product->name.' must be 0 or more.');
            }
            $system = (int) ($onHand[$product->id] ?? 0);
            $lines[] = ['product_id' => $product->id, 'actual_quantity' => (int) $actual];
            $difference = (int) $actual - $system;
            $fields[] = ['label' => $product->name, 'value' => $system.' → '.(int) $actual.' ('.($difference >= 0 ? '+' : '').$difference.')'];
        }

        $description = trim((string) ($input['description'] ?? ''));
        if ($description !== '') {
            $fields[] = ['label' => 'Notes', 'value' => $description];
        }
        $fields[] = ['label' => 'Status', 'value' => 'Saved as a draft; stock changes after approval'];

        return new PreparedAction('Stock adjustment (draft)', array_filter([
            'reason' => $reason,
            'items' => $lines,
            'description' => $description !== '' ? $description : null,
        ]), $fields, 'Save draft');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        if (! $context->location) {
            return ActionResult::failed('The store this was meant for no longer exists.');
        }

        return $this->call->run(
            'domains.inventory.adjustments.store',
            [...$input, 'location_id' => $context->location->id, 'type' => 'recount'],
            ['domain' => $context->domain],
            $context->user,
            'Saved the stock adjustment as a draft. Submit it for approval under Inventory → Stock Adjustments; stock changes once it is approved.',
        );
    }
}
