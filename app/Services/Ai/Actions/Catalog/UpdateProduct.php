<?php

namespace App\Services\Ai\Actions\Catalog;

use App\Models\Category;
use App\Models\Product\Product;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Changes a product's price, cost, name, category, SKU or barcode, like editing it under Products → Items. */
class UpdateProduct implements AssistantAction
{
    use DescribesValues;

    private const CHANGEABLE = ['name', 'price', 'cost', 'category_id', 'sold_type', 'sku', 'barcode', 'description'];

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'update_product';
    }

    public function permission(): string
    {
        return 'products.update';
    }

    public function description(): string
    {
        return 'Propose changing an existing product (find its product_id with find_products). Send only the fields that change.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('Product id from find_products')->required(),
            'name' => $schema->string()->description('New name'),
            'price' => $schema->number()->description('New selling price in pesos'),
            'cost' => $schema->number()->description('New cost in pesos'),
            'category_id' => $schema->integer()->description('New category id from list_categories'),
            'sold_type' => $schema->string()->description('New sold type from list_sold_types'),
            'sku' => $schema->string()->description('New SKU'),
            'barcode' => $schema->string()->description('New barcode'),
            'description' => $schema->string()->description('New description'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $id = $input['product_id'] ?? null;
        $product = $id ? Product::query()->forDomain($context->domain->name_slug)->with('category')->find($id) : null;
        if (! $product) {
            throw new ActionInputException('No such product in this business. Use find_products to get its product_id.');
        }

        $changes = $this->filled($input, self::CHANGEABLE);
        if ($changes === []) {
            throw new ActionInputException('Say what to change on the product (price, cost, name, category, SKU, barcode or description).');
        }

        $fields = [['label' => 'Product', 'value' => $product->name]];
        $data = [];

        if (isset($changes['name'])) {
            $data['name'] = trim((string) $changes['name']);
            $fields[] = ['label' => 'Name', 'value' => $this->change($product->name, $data['name'])];
        }
        foreach (['price' => 'Price', 'cost' => 'Cost'] as $key => $label) {
            if (isset($changes[$key])) {
                if (! is_numeric($changes[$key]) || $changes[$key] < 0) {
                    throw new ActionInputException("The {$key} must be a number of pesos (0 or more).");
                }
                $data[$key] = (float) $changes[$key];
                $old = $product->{$key} !== null ? $this->peso($product->{$key}) : null;
                $fields[] = ['label' => $label, 'value' => $this->change($old, $this->peso($data[$key]))];
            }
        }
        if (isset($changes['category_id'])) {
            $category = Category::query()->forDomain($context->domain->name_slug)->find($changes['category_id']);
            if (! $category) {
                throw new ActionInputException('That category does not exist in this business. Use list_categories.');
            }
            $data['category_id'] = $category->id;
            $fields[] = ['label' => 'Category', 'value' => $this->change($product->category?->name, $category->name)];
        }
        if (isset($changes['sold_type'])) {
            $data['sold_type'] = SoldTypes::resolve($changes['sold_type']);
            $fields[] = ['label' => 'Sold per', 'value' => $this->change($product->sold_type, $data['sold_type'])];
        }
        foreach (['sku' => ['SKU', 'SKU'], 'barcode' => ['barcode', 'Barcode'], 'description' => ['description', 'Description']] as $key => [$column, $label]) {
            if (isset($changes[$key])) {
                $data[$column] = trim((string) $changes[$key]);
                $fields[] = ['label' => $label, 'value' => $this->change($product->{$column}, $data[$column])];
            }
        }

        return new PreparedAction('Change product', ['product_id' => $product->id, 'changes' => $data], $fields, 'Save changes');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $product = Product::query()->forDomain($context->domain->name_slug)->find($input['product_id']);
        if (! $product) {
            return ActionResult::failed('That product no longer exists.');
        }

        // The edit form always sends the whole product, so start from what it has now. Modifier
        // groups and the product image stay as they are (no modifier_groups_present, no upload).
        $current = [
            'name' => $product->name,
            'description' => $product->description,
            'sold_type' => $product->sold_type,
            'price' => $product->price,
            'cost' => $product->cost,
            'category_id' => $product->category_id,
            'SKU' => $product->SKU,
            'barcode' => $product->barcode,
            'representation_type' => $product->representation_type,
            'representation' => $product->representation,
        ];

        return $this->call->run(
            'domains.products.update',
            array_filter([...$current, ...$input['changes']], fn ($v) => $v !== null),
            ['domain' => $context->domain, 'product' => $product],
            $context->user,
            'Updated "'.($input['changes']['name'] ?? $product->name).'".',
        );
    }
}
