<?php

namespace App\Services\Ai\Actions\Catalog;

use App\Services\Ai\Actions\ActionInputException;
use Illuminate\Support\Facades\DB;

/** The ways a product can be sold (Piece, Box…), matched without caring about letter case. */
class SoldTypes
{
    /** @return list<string> */
    public static function all(): array
    {
        return DB::table('product_sold_types')->orderBy('id')->pluck('name')->all();
    }

    /** The sold type named (or Piece when none is), as the system spells it. */
    public static function resolve(?string $wanted): string
    {
        $types = collect(self::all());
        if ($types->isEmpty()) {
            throw new ActionInputException('No sold types are set up in the system.');
        }

        $wanted = trim((string) $wanted);
        if ($wanted === '') {
            return $types->first(fn ($t) => strcasecmp($t, 'Piece') === 0) ?? $types->first();
        }

        return $types->first(fn ($t) => strcasecmp($t, $wanted) === 0)
            ?? throw new ActionInputException('Unknown sold type "'.$wanted.'". Choose one of: '.$types->implode(', ').'.');
    }
}
