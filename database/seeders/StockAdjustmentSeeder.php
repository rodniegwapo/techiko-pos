<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\User;
use Carbon\Carbon;

class StockAdjustmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get locations
        $jollibeeMain = InventoryLocation::where('code', 'JB-MAIN')->first();
        $jollibeeBranch = InventoryLocation::where('code', 'JB-BRANCH')->first();
        $jollibeeWarehouse = InventoryLocation::where('code', 'JB-WH')->first();
        
        $mcdonaldsMain = InventoryLocation::where('code', 'MC-MAIN')->first();
        $mcdonaldsBranch = InventoryLocation::where('code', 'MC-BRANCH')->first();
        $mcdonaldsWarehouse = InventoryLocation::where('code', 'MC-WH')->first();

        // Get products for each domain
        $jollibeeProducts = Product::where('domain', 'jollibee-corp')->take(8)->get();
        $mcdonaldsProducts = Product::where('domain', 'mcdonalds-corp')->take(8)->get();

        // Get users
        $jollibeeUsers = User::where('domain', 'jollibee-corp')->get();
        $mcdonaldsUsers = User::where('domain', 'mcdonalds-corp')->get();

        $statuses = ['draft', 'pending_approval', 'approved', 'rejected'];
        $reasons = [
            'physical_count',
            'damaged_goods',
            'expired_goods',
            'theft_loss',
            'supplier_error',
            'system_error',
            'promotion',
            'sample',
            'other'
        ];

        // Create adjustments for Jollibee locations
        if ($jollibeeMain && $jollibeeProducts->count() > 0) {
            $this->createAdjustmentsForLocation($jollibeeMain, $jollibeeProducts, $jollibeeUsers, 'jollibee-corp', $statuses, $reasons);
        }

        if ($jollibeeBranch && $jollibeeProducts->count() > 0) {
            $this->createAdjustmentsForLocation($jollibeeBranch, $jollibeeProducts, $jollibeeUsers, 'jollibee-corp', $statuses, $reasons);
        }

        if ($jollibeeWarehouse && $jollibeeProducts->count() > 0) {
            $this->createAdjustmentsForLocation($jollibeeWarehouse, $jollibeeProducts, $jollibeeUsers, 'jollibee-corp', $statuses, $reasons);
        }

        // Create adjustments for McDonald's locations
        if ($mcdonaldsMain && $mcdonaldsProducts->count() > 0) {
            $this->createAdjustmentsForLocation($mcdonaldsMain, $mcdonaldsProducts, $mcdonaldsUsers, 'mcdonalds-corp', $statuses, $reasons);
        }

        if ($mcdonaldsBranch && $mcdonaldsProducts->count() > 0) {
            $this->createAdjustmentsForLocation($mcdonaldsBranch, $mcdonaldsProducts, $mcdonaldsUsers, 'mcdonalds-corp', $statuses, $reasons);
        }

        if ($mcdonaldsWarehouse && $mcdonaldsProducts->count() > 0) {
            $this->createAdjustmentsForLocation($mcdonaldsWarehouse, $mcdonaldsProducts, $mcdonaldsUsers, 'mcdonalds-corp', $statuses, $reasons);
        }
    }

    private function createAdjustmentsForLocation($location, $products, $users, $domain, $statuses, $reasons)
    {
        $adjustmentsPerLocation = 12;
        $startDate = Carbon::now()->subDays(20);
        
        for ($i = 0; $i < $adjustmentsPerLocation; $i++) {
            $user = $users->random();
            $status = $statuses[array_rand($statuses)];
            $reason = $reasons[array_rand($reasons)];
            
            // Create random date within last 20 days
            $randomDate = $startDate->copy()->addDays(rand(0, 20))->addHours(rand(0, 23))->addMinutes(rand(0, 59));
            
            $adjustment = StockAdjustment::create([
                'adjustment_number' => $this->generateAdjustmentNumber(),
                'location_id' => $location->id,
                'created_by' => $user->id,
                'approved_by' => $status === 'approved' ? $users->where('role_level', '<=', 2)->random()->id : null,
                'status' => $status,
                'reason' => $reason,
                'description' => $this->generateAdjustmentNotes($reason, $location->name),
                'total_value_change' => 0, // Will be calculated after items are added
                'domain' => $domain,
                'created_at' => $randomDate,
                'updated_at' => $randomDate,
            ]);

            // Add 1-4 items to each adjustment
            $itemCount = rand(1, 4);
            $selectedProducts = $products->random($itemCount);
            $totalValue = 0;

            foreach ($selectedProducts as $product) {
                $systemQuantity = rand(10, 50);
                $actualQuantity = rand(5, 45);
                $adjustmentQuantity = $actualQuantity - $systemQuantity;
                $unitCost = rand(10, 500);
                $totalCostChange = $adjustmentQuantity * $unitCost;
                $totalValue += abs($totalCostChange);

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'system_quantity' => $systemQuantity,
                    'actual_quantity' => $actualQuantity,
                    'adjustment_quantity' => $adjustmentQuantity,
                    'unit_cost' => $unitCost,
                    'total_cost_change' => $totalCostChange,
                    'notes' => "Adjustment for {$product->name}",
                ]);
            }

            // Update the total value
            $adjustment->update(['total_value_change' => $totalValue]);
        }
    }

    private function generateAdjustmentNotes($reason, $locationName)
    {
        $notes = [
            'physical_count' => "Physical count adjustment at {$locationName} - inventory reconciliation",
            'damaged_goods' => "Damaged goods found at {$locationName} - quality control inspection",
            'expired_goods' => "Expired products at {$locationName} - disposal required",
            'theft_loss' => "Inventory discrepancy at {$locationName} - possible theft or loss",
            'supplier_error' => "Supplier error at {$locationName} - incorrect shipment",
            'system_error' => "System error at {$locationName} - data correction needed",
            'promotion' => "Promotional giveaway at {$locationName} - marketing campaign",
            'sample' => "Sample products at {$locationName} - promotional samples",
            'other' => "Other adjustment at {$locationName} - miscellaneous reason",
        ];

        return $notes[$reason] ?? "Stock adjustment at {$locationName}";
    }

    private function generateAdjustmentNumber()
    {
        return 'ADJ-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT) . '-' . time();
    }
}