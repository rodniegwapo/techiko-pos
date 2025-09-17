<?php

namespace App\Jobs;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SyncSaleDraft implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Sale $sale;

    protected array $items;

    // Prevent multiple jobs for the same sale at once
    public $uniqueFor = 10; // seconds

    public function uniqueId(): string
    {
        return (string) $this->sale->id;
    }

    /**
     * Create a new job instance.
     */
    public function __construct(Sale $sale, array $items)
    {
        $this->sale = $sale;
        $this->items = $items;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            foreach ($this->items as $item) {
                $saleItem = $this->sale->saleItems()->updateOrCreate(
                    ['product_id' => $item['id']],
                    [
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['price'],
                        // no need to set subtotal or discount, handled in model
                    ]
                );

                // Handle discounts if provided
                if (! empty($item['discount_id'])) {
                    $discount = \App\Models\Product\Discount::find($item['discount_id']);
                    if ($discount) {
                        $saleItem->setDiscountAmount($discount->type, (float) $discount->value);
                        $saleItem->discounts()->syncWithoutDetaching([$discount->id]);
                    }
                } else {
                    $saleItem->discount = 0;
                    $saleItem->save();
                }
            }

            // Recalculate sale totals
            $this->sale->recalcTotals();
        }, 5); // retry 5 times if deadlock
    }
}
