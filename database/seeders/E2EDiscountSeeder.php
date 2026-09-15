<?php

namespace Database\Seeders;

use App\Models\Product\Discount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright discounts suite (tests/e2e/discounts). Runs after E2ECategorySeeder.
 *
 * Discounts are organization-wide, so every worker gets uniquely named ones: an amount discount
 * with no dates and a percentage discount with a date range. A McDonald's fixture discount is the
 * target for cross-organization checks, so a failing check can only disturb test data. Discounts
 * created by tests ("E2E New Disc …") are removed, in any organization. Local/testing only.
 */
class E2EDiscountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EDiscountSeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            Discount::where(fn ($q) => $q->where('name', 'like', 'E2E Disc %')->orWhere('name', 'like', 'E2E New Disc %'))->delete();

            $workers = [];
            for ($n = 1; $n <= E2EWalletSeeder::WORKERS; $n++) {
                $amount = Discount::create([
                    'domain' => 'jollibee-corp',
                    'name' => "E2E Disc W{$n} Amount",
                    'type' => 'amount',
                    'scope' => 'order',
                    'value' => 50,
                    'min_order_amount' => 200,
                    'start_date' => null,
                    'end_date' => null,
                    'is_active' => true,
                ]);
                $percent = Discount::create([
                    'domain' => 'jollibee-corp',
                    'name' => "E2E Disc W{$n} Percent",
                    'type' => 'percentage',
                    'scope' => 'product',
                    'value' => 15,
                    'start_date' => '2026-01-01 00:00:00',
                    'end_date' => '2030-12-31 23:59:00',
                    'is_active' => true,
                ]);

                $workers[$n] = [
                    'amount' => ['id' => $amount->id, 'name' => $amount->name, 'value' => 50, 'minOrder' => 200],
                    'percent' => ['id' => $percent->id, 'name' => $percent->name, 'value' => 15],
                ];
            }

            $other = Discount::create([
                'domain' => 'mcdonalds-corp',
                'name' => 'E2E Disc McDonalds',
                'type' => 'amount',
                'scope' => 'order',
                'value' => 25,
                'is_active' => true,
            ]);

            return ['workers' => $workers, 'otherOrg' => ['id' => $other->id, 'name' => $other->name, 'value' => 25]];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        // `discounts` belongs to E2ESalesSeeder.
        $all['discountPage'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
