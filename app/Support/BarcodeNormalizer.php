<?php

namespace App\Support;

class BarcodeNormalizer
{
    public static function normalize(?string $barcode): string
    {
        if ($barcode === null) {
            return '';
        }

        return trim($barcode);
    }
}
