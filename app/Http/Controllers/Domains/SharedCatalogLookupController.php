<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SharedProduct;
use App\Support\BarcodeNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedCatalogLookupController extends Controller
{
    /**
     * JSON lookup by barcode against the global shared catalog.
     */
    public function lookup(Request $request, Domain $domain): JsonResponse
    {
        $barcode = BarcodeNormalizer::normalize((string) $request->query('barcode', ''));
        if ($barcode === '') {
            return response()->json([
                'message' => 'Barcode is required.',
                'found' => false,
                'data' => null,
            ], 422);
        }

        $row = SharedProduct::query()->where('barcode', $barcode)->first();

        if (! $row) {
            return response()->json([
                'found' => false,
                'data' => null,
            ]);
        }

        return response()->json([
            'found' => true,
            'data' => [
                'barcode' => $row->barcode,
                'name' => $row->name,
                'description' => $row->description,
                'category_label' => $row->category_label,
                'sold_type' => $row->sold_type,
                'representation_type' => $row->representation_type,
                'representation' => $row->representation,
            ],
        ]);
    }
}
