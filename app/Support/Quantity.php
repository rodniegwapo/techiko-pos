<?php

namespace App\Support;

use App\Models\Product\Product;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * How much of a product: whole numbers for things sold by the piece, up to three decimals for
 * things sold by weight or volume (1.5 kg, 0.75 L).
 */
class Quantity
{
    public const DECIMALS = 3;

    /** The smallest amount that can be sold of anything. */
    public const MIN = 0.001;

    public static function round(float|int|string|null $quantity): float
    {
        return round((float) $quantity, self::DECIMALS);
    }

    /** Whether this amount suits the product: any amount by weight/volume, whole numbers otherwise. */
    public static function fits(?Product $product, float|int|string|null $quantity): bool
    {
        $quantity = self::round($quantity);

        return ($product?->allowsDecimal() ?? false) || abs($quantity - round($quantity)) < 0.0005;
    }

    /**
     * Checks a list of [product_id, quantity] rows (receive, transfer, adjust): a part of a unit
     * only for products sold by weight or volume.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws ValidationException
     */
    public static function assertRowsFit(array $rows, string $field = 'items', string $key = 'quantity'): void
    {
        $products = Product::query()->whereIn('id', array_column($rows, 'product_id'))->get()->keyBy('id');
        foreach (array_values($rows) as $i => $row) {
            $product = $products->get((int) ($row['product_id'] ?? 0));
            if (isset($row[$key]) && ! self::fits($product, $row[$key])) {
                throw ValidationException::withMessages([
                    "{$field}.{$i}.{$key}" => ($product?->name ?? 'This product').' is sold by the '.($product?->quantityUnit() ?? 'piece').': use a whole number.',
                ]);
            }
        }
    }

    /**
     * A validation rule for a quantity of the given product (or of the product the rule finds).
     *
     * @param  Product|Closure(): ?Product|null  $product
     */
    public static function rule(Product|Closure|null $product): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($product) {
            $product = $product instanceof Closure ? $product() : $product;
            if (! self::fits($product, $value)) {
                $fail(($product?->name ?? 'This product').' is sold by the '.($product?->quantityUnit() ?? 'piece').': use a whole number.');
            }
        };
    }
}
