<?php

namespace App\Support;

class ProductPayloadNormalizer
{
    public const DEFAULT_COLOR_HEX = '94a3b8';

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function applyRepresentationAndCostDefaults(array $validated): array
    {
        $type = isset($validated['representation_type'])
            ? trim((string) $validated['representation_type'])
            : '';

        if ($type === '') {
            $validated['representation_type'] = 'color';
            $type = 'color';
        }

        $rep = isset($validated['representation'])
            ? trim((string) $validated['representation'])
            : '';

        if ($type === 'color' && $rep === '') {
            $validated['representation'] = self::DEFAULT_COLOR_HEX;
        }

        if (! array_key_exists('cost', $validated) || $validated['cost'] === '' || $validated['cost'] === null) {
            $validated['cost'] = null;
        }

        return $validated;
    }
}
