<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VoidLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright void logs suite (tests/e2e/sales/void-logs.spec.js).
 *
 * A void log belongs to the organization whose sale it came from, so the page has to be given
 * voids from more than one to have anything to keep apart: three for Jollibee and one for
 * McDonald's, each against its own product so a search matches exactly one of them and nothing the
 * sales suite voids while running alongside.
 *
 * One of the three is dated a year back, so the date filter has something to leave out, and the
 * amounts and reasons are fixed so the list can be read without guessing. Rebuilt every run;
 * local/testing only.
 */
class E2EVoidLogSeeder extends Seeder
{
    private const MARKER = 'E2E-VOID';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EVoidLogSeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            $this->wipe();

            $recent = [
                $this->void('jollibee-corp', 'Void Burger', 250, 'E2E wrong item rung up', now()->subHours(2)),
                $this->void('jollibee-corp', 'Void Fries', 75, 'E2E customer changed their mind', now()->subHours(3)),
            ];

            // Dated a year back, so the date range filter has one to leave out.
            $old = $this->void('jollibee-corp', 'Void Sundae', 120, 'E2E voided last year', now()->subYear());

            $other = $this->void('mcdonalds-corp', 'Void McWidget', 999, 'E2E another organization', now()->subHours(1));

            return [
                'recent' => $recent,
                'old' => $old,
                'other' => $other,
            ];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['voidLogs'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Removes what an earlier run left, newest relation first so no foreign key holds. */
    private function wipe(): void
    {
        $saleIds = Sale::where('invoice_number', 'like', self::MARKER.'%')->pluck('id');
        $itemIds = SaleItem::withTrashed()->whereIn('sale_id', $saleIds)->pluck('id');

        VoidLog::whereIn('sale_item_id', $itemIds)->delete();
        SaleItem::withTrashed()->whereIn('id', $itemIds)->forceDelete();
        Sale::whereIn('id', $saleIds)->delete();
        Product::where('name', 'like', 'E2E Void %')->forceDelete();
    }

    /**
     * One voided line: a paid sale with a single item, the item struck off the way voiding does it,
     * and the log that records who voided it, who approved it and why.
     */
    private function void(string $domain, string $productName, int $amount, string $reason, $at): array
    {
        $cashier = $this->staff($domain, 'cashier');
        $approver = $this->staff($domain, 'manager');

        $product = Product::create([
            'domain' => $domain,
            'name' => "E2E {$productName}",
            'sold_type' => 'piece',
            'price' => $amount,
            'cost' => $amount / 2,
            'SKU' => strtoupper(str_replace(' ', '-', "E2E {$productName}")),
            'track_inventory' => false,
            'unit_of_measure' => 'piece',
        ]);

        $sale = Sale::create([
            'domain' => $domain,
            'invoice_number' => self::MARKER.'-'.strtoupper(str_replace(' ', '', $productName)),
            'user_id' => $cashier->id,
            'total_amount' => $amount,
            'grand_total' => $amount,
            'payment_status' => 'paid',
            'transaction_date' => $at,
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);
        $item->delete();

        $log = VoidLog::create([
            'sale_item_id' => $item->id,
            'user_id' => $cashier->id,
            'approver_id' => $approver->id,
            'reason' => $reason,
            'amount' => $amount,
        ]);
        $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return [
            'id' => $log->id,
            'domain' => $domain,
            'product' => $product->name,
            'amount' => $amount,
            'reason' => $reason,
            'cashier' => $cashier->name,
            'approver' => $approver->name,
            'invoice_number' => $sale->invoice_number,
            'created_at' => $at->toDateString(),
        ];
    }

    private function staff(string $domain, string $role): User
    {
        $user = User::where('domain', $domain)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->orderBy('id')
            ->first();

        if (! $user) {
            throw new RuntimeException("No {$role} seeded for {$domain}; run DatabaseSeeder first.");
        }

        return $user;
    }
}
