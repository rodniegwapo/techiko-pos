<?php

namespace App\Services\Ai\Actions\Catalog;

use App\Models\Category;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\HasInputTemplate;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;

/** Adds a product to the store the user works in, like Products → Items → Create Product. */
class CreateProduct implements AssistantAction, HasInputTemplate
{
    use DescribesValues;

    /** Category names listed in the form; past this many it shows examples instead of all of them. */
    private const TEMPLATE_CATEGORIES = 8;

    public function __construct(private readonly ControllerCall $call) {}

    public function templateTitle(): string
    {
        return 'Add product';
    }

    public function templateGuide(): ?string
    {
        return 'add-product';
    }

    public function template(ActionContext $context): array
    {
        $categories = $this->categories($context)->pluck('name');
        $categoryHint = $categories->isEmpty() ? null
            : ($categories->count() > self::TEMPLATE_CATEGORIES ? 'e.g. ' : 'one of: ')
                .$categories->take(self::TEMPLATE_CATEGORIES)->implode(', ');

        return [
            ['label' => 'Name', 'required' => true, 'hint' => null],
            ['label' => 'Price', 'required' => true, 'hint' => '₱'],
            ['label' => 'Category', 'required' => false, 'hint' => $categoryHint],
            ['label' => 'Cost', 'required' => false, 'hint' => '₱'],
            ['label' => 'Sold per', 'required' => false, 'hint' => 'one of: '.implode(', ', SoldTypes::all())],
            ['label' => 'SKU', 'required' => false, 'hint' => null],
            ['label' => 'Barcode', 'required' => false, 'hint' => null],
        ];
    }

    public function name(): string
    {
        return 'create_product';
    }

    public function permission(): string
    {
        return 'products.store';
    }

    public function description(): string
    {
        return 'Propose adding a new product to the store the user works in. Name and price are required. Give the category by name (category) or id (category_id from list_categories), and sold_type from list_sold_types.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Product name')->required(),
            'price' => $schema->number()->description('Selling price in pesos')->required(),
            'cost' => $schema->number()->description('What the product costs the business, in pesos (optional)'),
            'category_id' => $schema->integer()->description('Category id from list_categories (optional)'),
            'category' => $schema->string()->description('Category name, as the user wrote it (optional; used when there is no category_id)'),
            'sold_type' => $schema->string()->description('How it is sold, from list_sold_types (default Piece)'),
            'sku' => $schema->string()->description('SKU (optional)'),
            'barcode' => $schema->string()->description('Barcode (optional)'),
            'description' => $schema->string()->description('Short description (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ActionInputException('The product name is required.');
        }
        if (! is_numeric($input['price'] ?? null) || $input['price'] < 0) {
            throw new ActionInputException('The price is required and must be a number of pesos (0 or more).');
        }
        if (! $context->location) {
            throw new ActionInputException('No store is selected. The user should pick a store first.');
        }

        $soldType = SoldTypes::resolve($input['sold_type'] ?? null);

        $category = null;
        if (! empty($input['category_id'])) {
            $category = $this->categories($context)->firstWhere('id', (int) $input['category_id']);
            if (! $category) {
                throw new ActionInputException('That category does not exist in this business. Use list_categories.');
            }
        } elseif (trim((string) ($input['category'] ?? '')) !== '') {
            $wanted = trim((string) $input['category']);
            $categories = $this->categories($context);
            $category = $categories->first(fn (Category $c) => strcasecmp($c->name, $wanted) === 0);
            if (! $category) {
                throw new ActionInputException('There is no category "'.$wanted.'" in this business. The categories are: '
                    .($categories->pluck('name')->implode(', ') ?: 'none yet').'.');
            }
        }

        $data = [
            'name' => $name,
            'price' => (float) $input['price'],
            'sold_type' => $soldType,
            'category_id' => $category?->id,
            'cost' => is_numeric($input['cost'] ?? null) ? (float) $input['cost'] : null,
            'SKU' => trim((string) ($input['sku'] ?? '')) ?: null,
            'barcode' => trim((string) ($input['barcode'] ?? '')) ?: null,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
        ];

        $fields = [
            ['label' => 'Name', 'value' => $name],
            ['label' => 'Price', 'value' => $this->peso($data['price'])],
        ];
        if ($data['cost'] !== null) {
            $fields[] = ['label' => 'Cost', 'value' => $this->peso($data['cost'])];
        }
        $fields[] = ['label' => 'Category', 'value' => $category?->name ?? 'None'];
        $fields[] = ['label' => 'Sold per', 'value' => $soldType];
        foreach (['SKU' => 'SKU', 'barcode' => 'Barcode', 'description' => 'Description'] as $key => $label) {
            if ($data[$key] !== null) {
                $fields[] = ['label' => $label, 'value' => $data[$key]];
            }
        }
        $fields[] = ['label' => 'Store', 'value' => $context->location->name];

        return new PreparedAction('Add product', array_filter($data, fn ($v) => $v !== null), $fields, 'Add product');
    }

    /** @return Collection<int, Category> This business's categories, A–Z. */
    private function categories(ActionContext $context): Collection
    {
        return Category::query()->forDomain($context->domain->name_slug)->orderBy('name')->get(['id', 'name']);
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        if (! $context->location) {
            return ActionResult::failed('The store this was meant for no longer exists.');
        }

        return $this->call->run(
            'domains.products.store',
            [...$input, 'location_id' => $context->location->id],
            ['domain' => $context->domain],
            $context->user,
            'Added "'.$input['name'].'" to '.$context->location->name.'.',
        );
    }
}
