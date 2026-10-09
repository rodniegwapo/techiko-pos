<?php

namespace App\Services;

use App\Models\Modifier;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Options picked when a product is rung up (Size, Add-ons…): checked against the product's groups,
 * priced on the server, and kept on the sale line.
 */
class ProductModifierService
{
    /**
     * The options picked for a product, checked against its groups: each must be one it offers, a
     * required group needs a pick, and no group may have more than it allows.
     *
     * @param  array<int, int|string>  $modifierIds
     * @return array{modifiers: Collection<int, Modifier>, key: ?string, price_delta: float, cost_delta: float}
     */
    public function resolve(Product $product, array $modifierIds): array
    {
        $ids = collect($modifierIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $groups = $product->modifierGroups()->with('activeModifiers')->get();
        $offered = $groups->flatMap->activeModifiers->keyBy('id');

        if ($ids->diff($offered->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'modifier_ids' => "One or more options aren't offered for {$product->name}.",
            ]);
        }

        $picked = $ids->map(fn ($id) => $offered[$id]);

        foreach ($groups as $group) {
            $count = $picked->where('modifier_group_id', $group->id)->count();

            // A group with no options left to pick can't hold up a sale.
            if ($group->is_required && $count === 0 && $group->activeModifiers->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'modifier_ids' => "Choose the {$group->name} for {$product->name}.",
                ]);
            }

            $max = $group->maxPicks();
            if ($max !== null && $count > $max) {
                throw ValidationException::withMessages([
                    'modifier_ids' => $max === 1
                        ? "Choose only one {$group->name} for {$product->name}."
                        : "Choose up to {$max} {$group->name} for {$product->name}.",
                ]);
            }
        }

        return [
            'modifiers' => $picked,
            'key' => $ids->isEmpty() ? null : $ids->implode(','),
            'price_delta' => round((float) $picked->sum(fn ($m) => (float) $m->price_delta), 2),
            'cost_delta' => round((float) $picked->sum(fn ($m) => (float) $m->cost_delta), 4),
        ];
    }

    /**
     * Add a product, with its options and note, to a pending sale. It goes onto the line already there
     * for the same product, options and note; otherwise it starts a new line, priced here.
     *
     * @param  array<int, int|string>  $modifierIds
     */
    public function addToSale(Sale $sale, Product $product, int $quantity, array $modifierIds = [], ?string $notes = null): SaleItem
    {
        $resolved = $this->resolve($product, $modifierIds);
        $notes = trim((string) $notes) !== '' ? trim((string) $notes) : null;

        $line = $sale->saleItems()
            ->where('product_id', $product->id)
            ->when($resolved['key'], fn ($q, $key) => $q->where('modifier_key', $key), fn ($q) => $q->whereNull('modifier_key'))
            ->when($notes, fn ($q, $n) => $q->where('notes', $n), fn ($q) => $q->whereNull('notes'))
            ->first();

        if ($line) {
            // save(), not increment(): increment skips the saving hook that keeps the line subtotal right.
            $line->quantity += $quantity;
            $line->save();

            return $line;
        }

        $attributes = [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => round((float) $product->price + $resolved['price_delta'], 2),
            'modifier_key' => $resolved['key'],
            'notes' => $notes,
        ];
        // Options that cost something to make add to the line's cost; otherwise the product's cost is used.
        if ($resolved['cost_delta'] != 0) {
            $attributes['unit_cost'] = (float) $product->cost + $resolved['cost_delta'];
        }

        $line = $sale->saleItems()->create($attributes);

        $groupNames = $product->modifierGroups->pluck('name', 'id');
        foreach ($resolved['modifiers'] as $modifier) {
            $line->modifiers()->create([
                'modifier_id' => $modifier->id,
                'group_name' => $groupNames[$modifier->modifier_group_id] ?? '',
                'name' => $modifier->name,
                'price_delta' => $modifier->price_delta,
                'cost_delta' => $modifier->cost_delta,
            ]);
        }

        return $line;
    }
}
