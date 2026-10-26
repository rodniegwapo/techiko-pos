<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderViewController extends Controller
{
    /**
     * Get order data for customer view
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        // Only an order of the store whose display link this is.
        $store = InventoryLocation::forDisplayToken($request->query('display'));
        if (! $store) {
            return $this->unknownDisplay();
        }

        try {
            $order = Sale::with([
                'saleItems.product',
                'saleItems.modifiers',
                'customer.tier',
            ])->where('location_id', $store->id)->where('domain', $store->domain)->findOrFail($orderId);

            // Check if order is still active (not completed/voided)
            if (in_array($order->payment_status, ['paid', 'voided'])) {
                return response()->json([
                    'error' => 'Order not available for viewing',
                    'message' => 'This order has been completed or cancelled',
                ], 404);
            }

            // Format order data for customer view
            $orderData = [
                'id' => $order->id,
                'order_number' => $order->order_number ?? 'ORD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->payment_status,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                // Add sale totals directly to order object for easier access
                'total_amount' => (float) $order->total_amount,
                'discount_amount' => (float) $order->discount_amount,
                'tax_amount' => (float) $order->tax_amount,
                'grand_total' => (float) $order->grand_total,
                'items' => $order->saleItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name ?? 'Unknown Product',
                        'modifiers' => $item->modifierSummary(),
                        'notes' => $item->notes,
                        'product_sku' => $item->product->SKU ?? 'N/A',
                        'quantity' => $item->quantity,
                        'quantity_unit' => $item->product?->quantityUnit() ?? 'pc',
                        'allows_decimal' => (bool) $item->product?->allowsDecimal(),
                        'unit_price' => (float) $item->unit_price,
                        'discount' => (float) $item->discount,
                        'discount_amount' => (float) $item->discount,
                        'subtotal' => (float) $item->subtotal,
                        'total_price' => (float) $item->subtotal,
                    ];
                }),
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'email' => $order->customer->email,
                    // The tier as the organization named and coloured it in Tier Management.
                    'tier_info' => (fn ($tier) => ['name' => $tier['name'], 'color' => $tier['color'] ?: $this->getTierColor($order->customer->tier ?? 'bronze')])($order->customer->getTierInfo()),
                    'loyalty_points' => $order->customer->loyalty_points ?? 0,
                ] : null,
                'totals' => [
                    'subtotal' => (float) $order->total_amount,
                    'discount_amount' => (float) $order->discount_amount,
                    'tax_amount' => (float) $order->tax_amount,
                    'grand_total' => (float) $order->grand_total,
                ],
            ];

            return response()->json([
                'success' => true,
                'order' => $orderData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Order not found',
                'message' => 'The order you are looking for does not exist',
            ], 404);
        }
    }

    /**
     * Get the most recent pending order
     */
    public function getRecentPending(Request $request): JsonResponse
    {
        // The display shows its own store's open order, never another business's.
        $store = InventoryLocation::forDisplayToken($request->query('display'));
        if (! $store) {
            return $this->unknownDisplay();
        }

        try {
            $order = Sale::with([
                'saleItems.product',
                'saleItems.modifiers',
                'customer.tier',
            ])
                ->where('payment_status', 'pending')
                ->where('location_id', $store->id)
                ->where('domain', $store->domain)
                ->latest('updated_at')
                ->first();

            if (! $order) {
                return response()->json([
                    'success' => true,
                    'order' => null,
                    'message' => 'No pending orders found',
                ]);
            }

            // Format order data for customer view
            $orderData = [
                'id' => $order->id,
                'order_number' => $order->order_number ?? 'ORD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->payment_status,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                // Add sale totals directly to order object for easier access
                'total_amount' => (float) $order->total_amount,
                'discount_amount' => (float) $order->discount_amount,
                'tax_amount' => (float) $order->tax_amount,
                'grand_total' => (float) $order->grand_total,
                'items' => $order->saleItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name ?? 'Unknown Product',
                        'modifiers' => $item->modifierSummary(),
                        'notes' => $item->notes,
                        'product_sku' => $item->product->SKU ?? 'N/A',
                        'quantity' => $item->quantity,
                        'quantity_unit' => $item->product?->quantityUnit() ?? 'pc',
                        'allows_decimal' => (bool) $item->product?->allowsDecimal(),
                        'unit_price' => (float) $item->unit_price,
                        'discount' => (float) $item->discount,
                        'discount_amount' => (float) $item->discount,
                        'subtotal' => (float) $item->subtotal,
                        'total_price' => (float) $item->subtotal,
                    ];
                }),
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'email' => $order->customer->email,
                    // The tier as the organization named and coloured it in Tier Management.
                    'tier_info' => (fn ($tier) => ['name' => $tier['name'], 'color' => $tier['color'] ?: $this->getTierColor($order->customer->tier ?? 'bronze')])($order->customer->getTierInfo()),
                    'loyalty_points' => $order->customer->loyalty_points ?? 0,
                ] : null,
                'totals' => [
                    'subtotal' => (float) $order->total_amount,
                    'discount_amount' => (float) $order->discount_amount,
                    'tax_amount' => (float) $order->tax_amount,
                    'grand_total' => (float) $order->grand_total,
                ],
            ];

            return response()->json([
                'success' => true,
                'order' => $orderData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch recent order',
                'message' => 'An error occurred while fetching the order',
            ], 500);
        }
    }

    /**
     * Get tier color for customer display
     */
    private function getTierColor(string $tier): string
    {
        return match (strtolower($tier)) {
            'bronze' => '#CD7F32',
            'silver' => '#C0C0C0',
            'gold' => '#FFD700',
            'platinum' => '#E5E4E2',
            default => '#CD7F32'
        };
    }

    /** A display link without a valid store token: nothing to show. */
    private function unknownDisplay(): JsonResponse
    {
        return response()->json([
            'error' => 'Unknown display',
            'message' => 'Open the customer display from the Sales screen of your store.',
        ], 404);
    }
}
