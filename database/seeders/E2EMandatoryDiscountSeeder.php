<?php

namespace Database\Seeders;

use App\Models\MandatoryDiscount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright mandatory discounts suite (tests/e2e/mandatory-discounts). Runs after
 * E2EDiscountSeeder.
 *
 * Every worker gets uniquely named mandatory discounts: an active percentage one and an inactive
 * amount one. A McDonald's fixture is the target for cross-organization checks, so a failing check
 * can only disturb test data. Mandatory discounts created by tests ("E2E New Mand …") are removed.
 * The sales suite's "E2E Senior 20%" / "E2E PWD 20%" belong to E2ESalesSeeder and aren't touched.
 * Local/testing only.
 */
class E2EMandatoryDiscountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EMandatoryDiscountSeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            MandatoryDiscount::where(fn ($q) => $q->where('name', 'like', 'E2E Mand %')->orWhere('name', 'like', 'E2E New Mand %'))->delete();

            $workers = [];
            for ($n = 1; $n <= E2EWalletSeeder::WORKERS; $n++) {
                $percent = MandatoryDiscount::create([
                    'domain' => 'jollibee-corp',
                    'name' => "E2E Mand W{$n} Percent",
                    'type' => 'percentage',
                    'value' => 12.5,
                    'is_active' => true,
                ]);
                $amount = MandatoryDiscount::create([
                    'domain' => 'jollibee-corp',
                    'name' => "E2E Mand W{$n} Amount",
                    'type' => 'amount',
                    'value' => 75,
                    'is_active' => false,
                ]);

                $workers[$n] = [
                    'percent' => ['id' => $percent->id, 'name' => $percent->name, 'value' => 12.5],
                    'amount' => ['id' => $amount->id, 'name' => $amount->name, 'value' => 75],
                ];
            }

            $other = MandatoryDiscount::create([
                'domain' => 'mcdonalds-corp',
                'name' => 'E2E Mand McDonalds',
                'type' => 'percentage',
                'value' => 5,
                'is_active' => true,
            ]);

            return ['workers' => $workers, 'otherOrg' => ['id' => $other->id, 'name' => $other->name, 'value' => 5]];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['mandatoryPage'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
