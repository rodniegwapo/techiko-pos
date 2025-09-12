<?php

namespace App\Jobs;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SyncSaleDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $sale;
    protected $items;

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
            // delete old items
            $this->sale->items()->delete();

            // re-create items
            foreach ($this->items as $item) {
                $this->sale->items()->create([
                    'product_id' => $item['id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['price'],
                    'subtotal'   => $item['price'] * $item['quantity'],
                ]);
            }

            // update sale total
            $this->sale->total_amount = collect($this->items)
                ->sum(fn($i) => $i['quantity'] * $i['price']);
            $this->sale->save();
        });
    }
}
