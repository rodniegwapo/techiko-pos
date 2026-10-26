<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSoldType extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'allows_decimal' => 'boolean',
    ];

    /** @return array<string, array{unit: string, allows_decimal: bool}> Sold type name → how it is counted. */
    public static function rules(): array
    {
        return once(fn () => static::query()->get(['name', 'unit', 'allows_decimal'])
            // Keyed in lower case: older rows say "piece" where the type is "Piece".
            ->mapWithKeys(fn (self $t) => [strtolower($t->name) => ['unit' => $t->unit, 'allows_decimal' => (bool) $t->allows_decimal]])
            ->all());
    }
}
