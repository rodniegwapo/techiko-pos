<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Models\Product\Discount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        try {
            // Validate items array
            if (empty($this->items) || !is_array($this->items)) {
                Log::warning('SyncSaleDraft: Empty or invalid items array', [
                    'sale_id' => $this->sale->id,
                    'items' => $this->items
                ]);
                return;
            }

            // Get all discount IDs to fetch in one query
            $discountIds = collect($this->items)
                ->pluck('discount_id')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $discounts = empty($discountIds) ? collect() : Discount::whereIn('id', $discountIds)->get()->keyBy('id');

            DB::transaction(function () use ($discounts) {
                foreach ($this->items as $item) {
                    // Validate required item fields
                    if (!isset($item['id']) || !isset($item['quantity']) || !isset($item['price'])) {
                        Log::warning('SyncSaleDraft: Missing required item fields', [
                            'sale_id' => $this->sale->id,
                            'item' => $item
                        ]);
                        continue;
                    }

                    $saleItem = $this->sale->saleItems()->updateOrCreate(
                        ['product_id' => $item['id']],
                        [
                            'quantity' => max(1, (int) $item['quantity']), // Ensure minimum quantity of 1
                            'unit_price' => max(0, (float) $item['price']), // Ensure non-negative price
                        ]
                    );

                    // Handle discounts if provided
                    if (!empty($item['discount_id']) && $discounts->has($item['discount_id'])) {
                        $discount = $discounts->get($item['discount_id']);
                        
                        // Validate discount is active and applicable
                        if ($discount->is_active && 
                            (!$discount->start_date || now()->gte($discount->start_date)) &&
                            (!$discount->end_date || now()->lte($discount->end_date))) {
                            
                            $saleItem->setDiscountAmount($discount->type, (float) $discount->value);
                            $saleItem->discounts()->sync([$discount->id]);
                        } else {
                            // Discount is not valid, clear any existing discounts
                            $saleItem->setDiscountAmount(null, 0);
                            $saleItem->discounts()->detach();
                        }
                    } else {
                        // No discount or invalid discount_id, clear any existing discounts
                        $saleItem->setDiscountAmount(null, 0);
                        $saleItem->discounts()->detach();
                    }
                }

                // Recalculate sale totals
                $this->sale->recalcTotals();
            }, 5); // retry 5 times if deadlock

        } catch (\Exception $e) {
            Log::error('SyncSaleDraft failed', [
                'sale_id' => $this->sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to trigger job failure handling
            throw $e;
        }
    }
}
