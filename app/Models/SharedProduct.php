<?php

namespace App\Models;

use App\Support\BarcodeNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SharedProduct extends Model
{
    protected $guarded = ['id'];

    public function scopeBarcodeLookup(Builder $query, ?string $barcode): Builder
    {
        $normalized = BarcodeNormalizer::normalize($barcode);

        return $query->where('barcode', $normalized);
    }

    public static function normalizedBarcode(?string $barcode): string
    {
        return BarcodeNormalizer::normalize($barcode);
    }

    protected static function booted(): void
    {
        static::saving(function (SharedProduct $model) {
            $model->barcode = BarcodeNormalizer::normalize($model->barcode);
        });
    }
}
