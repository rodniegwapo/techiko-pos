<?php

namespace App\Traits;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared request handling for moving stock between stores: one product (the original form) or
 * several at once as `items`.
 */
trait HandlesStockTransfers
{
    /**
     * Validates a transfer and returns its rows as [['product_id' => …, 'quantity' => …], …].
     *
     * @return array{rows: array<int, array{product_id: int, quantity: int}>, from_location_id: int, to_location_id: int, notes: ?string, single: bool}
     */
    protected function validatedTransfer(Request $request): array
    {
        $single = ! $request->has('items');

        $validated = $request->validate([
            'from_location_id' => 'required|exists:inventory_locations,id',
            'to_location_id' => 'required|exists:inventory_locations,id|different:from_location_id',
            'notes' => 'nullable|string|max:500',
            ...($single
                ? [
                    'product_id' => 'required|exists:products,id',
                    'quantity' => 'required|integer|min:1',
                ]
                : [
                    'items' => 'required|array|min:1|max:100',
                    'items.*.product_id' => 'required|integer|distinct|exists:products,id',
                    'items.*.quantity' => 'required|integer|min:1',
                ]),
        ], [
            'items.*.product_id.distinct' => 'This product is already in the list.',
        ]);

        $rows = $single
            ? [['product_id' => (int) $validated['product_id'], 'quantity' => (int) $validated['quantity']]]
            : array_map(fn ($row) => [
                'product_id' => (int) $row['product_id'],
                'quantity' => (int) $row['quantity'],
            ], array_values($validated['items']));

        return [
            'rows' => $rows,
            'from_location_id' => (int) $validated['from_location_id'],
            'to_location_id' => (int) $validated['to_location_id'],
            'notes' => $validated['notes'] ?? null,
            'single' => $single,
        ];
    }

    /** 422 naming each row that's short, so the form can show the message under that row. */
    protected function transferShortageResponse(InsufficientStockException $e, array $transfer, InventoryLocation $from): JsonResponse
    {
        $errors = [];
        $messages = [];

        foreach ($e->getUnavailableItems() as $item) {
            $available = $item['available_quantity'] ?? 0;
            $message = "Only {$available} available at {$from->name}";
            $messages[] = "{$item['product_name']}: {$message}";

            if ($transfer['single']) {
                $errors['quantity'] = ["Only {$available} units available at source location"];

                continue;
            }
            foreach ($transfer['rows'] as $i => $row) {
                if ($row['product_id'] === (int) $item['product_id']) {
                    $errors["items.{$i}.quantity"] = [$message];
                }
            }
        }

        return response()->json([
            'success' => false,
            'errors' => $errors,
            'message' => $transfer['single']
                ? ($errors['quantity'][0] ?? 'Not enough stock')
                : 'Not enough stock: '.implode('; ', $messages),
        ], 422);
    }
}
