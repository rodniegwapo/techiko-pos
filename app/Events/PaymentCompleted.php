<?php

namespace App\Events;

use App\Models\InventoryLocation;
use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Sale $sale)
    {
        \Log::info('PaymentCompleted event created', [
            'sale_id' => $sale->id,
            'payment_status' => $sale->payment_status,
        ]);
    }

    public function broadcastOn(): Channel
    {
        // Only the customer display of this sale's store hears it: the channel is named by the
        // store's secret display token, never one channel shared by every business.
        $store = $this->sale->location ?? InventoryLocation::query()->find($this->sale->location_id);

        return new Channel($store ? $store->displayChannel() : 'customer-display.unassigned');
    }

    public function broadcastAs(): string
    {
        return 'PaymentCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'event_type' => 'payment_completed',
            'sale_id' => $this->sale->id,
            'order_number' => $this->sale->order_number ?? 'ORD-'.str_pad($this->sale->id, 6, '0', STR_PAD_LEFT),
            'payment_status' => $this->sale->payment_status,
            'payment_method' => $this->sale->payment_method,
            'total_amount' => (float) $this->sale->total_amount,
            'message' => 'Payment completed successfully. Order cleared for new transaction.',
        ];
    }
}
