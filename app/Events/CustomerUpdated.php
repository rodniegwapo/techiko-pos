<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Sale $sale) 
    {
        \Log::info("CustomerUpdated event created", [
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id
        ]);
    }

    public function broadcastOn(): Channel
    {
        return new Channel('order'); // Use the same channel as OrderUpdated
    }

    public function broadcastAs(): string
    {
        return 'CustomerUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->sale->fresh([
                'saleItems.product',
                'saleDiscounts',
                'saleItems.discounts',
                'customer'
            ])
        ];
    }
}
