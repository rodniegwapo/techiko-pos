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

class SyncSaleDraft implements ShouldQueue, ShouldBeUnique
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
        // Retry up to 5 times if deadlock happens
        DB::transaction(function () {
            foreach ($this->items as $item) {
                $this->sale->saleItems()->updateOrCreate(
                    ['product_id' => $item['id']],
                    [
                        'quantity'   => $item['quantity'],
                        'unit_price' => $item['price'],
                        'subtotal'   => $item['price'] * $item['quantity'],
                    ]
                );
            }

            // recalc total
            $this->sale->total_amount = collect($this->items)
                ->sum(fn($i) => $i['quantity'] * $i['price']);
            $this->sale->save();
        }, 5); // retry 5 times if deadlock
    }
}
