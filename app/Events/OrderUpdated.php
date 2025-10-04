<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Sale $sale) {}

    public function broadcastOn(): Channel
    {
        return new Channel('order'); // public channel
    }

    public function broadcastAs(): string
    {
        return 'OrderUpdated';
    }

    public function broadcastWith(): array
    {
        $sale = $this->sale->fresh([
            'saleItems.product',
            'saleDiscounts',
            'saleItems.discounts',
            'customer'
        ]);

        // Transform the data to match the API format
        $orderData = [
            'id' => $sale->id,
            'order_number' => $sale->order_number ?? 'ORD-' . str_pad($sale->id, 6, '0', STR_PAD_LEFT),
            'status' => $sale->payment_status,
            'created_at' => $sale->created_at,
            'updated_at' => $sale->updated_at,
            'total_amount' => (float) $sale->total_amount,
            'discount_amount' => (float) $sale->discount_amount,
            'tax_amount' => (float) $sale->tax_amount,
            'grand_total' => (float) $sale->grand_total,
            'saleItems' => $sale->saleItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? 'Unknown Product',
                    'product_sku' => $item->product->SKU ?? 'N/A',
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount,
                    'discount_amount' => (float) $item->discount,
                    'subtotal' => (float) $item->subtotal,
                    'total_price' => (float) $item->subtotal,
                ];
            }),
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'phone' => $sale->customer->phone,
                'email' => $sale->customer->email,
                'loyalty_points' => $sale->customer->loyalty_points ?? 0,
            ] : null,
        ];

        return [
            'event_type' => 'order_updated',
            'order' => $orderData
        ];
    }
}
