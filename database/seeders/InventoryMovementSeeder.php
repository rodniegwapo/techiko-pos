<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryMovement;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\User;
use Carbon\Carbon;

class InventoryMovementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get locations and products
        $jollibeeMain = InventoryLocation::where('code', 'JB-MAIN')->first();
        $jollibeeBranch = InventoryLocation::where('code', 'JB-BRANCH')->first();
        $jollibeeWarehouse = InventoryLocation::where('code', 'JB-WH')->first();
        
        $mcdonaldsMain = InventoryLocation::where('code', 'MC-MAIN')->first();
        $mcdonaldsBranch = InventoryLocation::where('code', 'MC-BRANCH')->first();
        $mcdonaldsWarehouse = InventoryLocation::where('code', 'MC-WH')->first();

        // Get products for each domain
        $jollibeeProducts = Product::where('domain', 'jollibee-corp')->take(5)->get();
        $mcdonaldsProducts = Product::where('domain', 'mcdonalds-corp')->take(5)->get();

        // Get users
        $jollibeeUsers = User::where('domain', 'jollibee-corp')->get();
        $mcdonaldsUsers = User::where('domain', 'mcdonalds-corp')->get();

        $movementTypes = [
            'sale' => 'Sale',
            'purchase' => 'Purchase',
            'adjustment' => 'Stock Adjustment',
            'transfer_in' => 'Transfer In',
            'transfer_out' => 'Transfer Out',
            'return' => 'Customer Return',
            'damage' => 'Damaged Goods',
            'theft' => 'Theft/Loss',
            'expired' => 'Expired Products',
            'promotion' => 'Promotional Giveaway',
        ];

        // Create movements for Jollibee locations
        if ($jollibeeMain && $jollibeeProducts->count() > 0) {
            $this->createMovementsForLocation($jollibeeMain, $jollibeeProducts, $jollibeeUsers, 'jollibee-corp', $movementTypes);
        }

        if ($jollibeeBranch && $jollibeeProducts->count() > 0) {
            $this->createMovementsForLocation($jollibeeBranch, $jollibeeProducts, $jollibeeUsers, 'jollibee-corp', $movementTypes);
        }

        if ($jollibeeWarehouse && $jollibeeProducts->count() > 0) {
            $this->createMovementsForLocation($jollibeeWarehouse, $jollibeeProducts, $jollibeeUsers, 'jollibee-corp', $movementTypes);
        }

        // Create movements for McDonald's locations
        if ($mcdonaldsMain && $mcdonaldsProducts->count() > 0) {
            $this->createMovementsForLocation($mcdonaldsMain, $mcdonaldsProducts, $mcdonaldsUsers, 'mcdonalds-corp', $movementTypes);
        }

        if ($mcdonaldsBranch && $mcdonaldsProducts->count() > 0) {
            $this->createMovementsForLocation($mcdonaldsBranch, $mcdonaldsProducts, $mcdonaldsUsers, 'mcdonalds-corp', $movementTypes);
        }

        if ($mcdonaldsWarehouse && $mcdonaldsProducts->count() > 0) {
            $this->createMovementsForLocation($mcdonaldsWarehouse, $mcdonaldsProducts, $mcdonaldsUsers, 'mcdonalds-corp', $movementTypes);
        }
    }

    private function createMovementsForLocation($location, $products, $users, $domain, $movementTypes)
    {
        $movementsPerLocation = 25;
        $startDate = Carbon::now()->subDays(30);
        
        for ($i = 0; $i < $movementsPerLocation; $i++) {
            $product = $products->random();
            $user = $users->random();
            $movementType = array_rand($movementTypes);
            $quantity = rand(1, 50);
            $unitCost = rand(10, 500);
            $totalCost = $quantity * $unitCost;
            
            // Create random date within last 30 days
            $randomDate = $startDate->copy()->addDays(rand(0, 30))->addHours(rand(0, 23))->addMinutes(rand(0, 59));
            
            $quantityChange = $movementType === 'sale' || $movementType === 'transfer_out' || $movementType === 'damage' || $movementType === 'theft' || $movementType === 'expired' ? -$quantity : $quantity;
            $quantityBefore = rand(0, 100); // Random starting quantity
            $quantityAfter = $quantityBefore + $quantityChange;

            InventoryMovement::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'user_id' => $user->id,
                'movement_type' => $movementType,
                'quantity_before' => $quantityBefore,
                'quantity_change' => $quantityChange,
                'quantity_after' => $quantityAfter,
                'unit_cost' => $unitCost,
                'total_cost' => $movementType === 'sale' || $movementType === 'transfer_out' || $movementType === 'damage' || $movementType === 'theft' || $movementType === 'expired' ? -$totalCost : $totalCost,
                'batch_number' => $this->generateReferenceNumber($movementType),
                'notes' => $this->generateNotes($movementType, $product->name),
                'domain' => $domain,
                'created_at' => $randomDate,
                'updated_at' => $randomDate,
            ]);
        }
    }

    private function generateReferenceNumber($movementType)
    {
        $prefixes = [
            'sale' => 'SALE',
            'purchase' => 'PUR',
            'adjustment' => 'ADJ',
            'transfer_in' => 'TR-IN',
            'transfer_out' => 'TR-OUT',
            'return' => 'RET',
            'damage' => 'DAM',
            'theft' => 'THF',
            'expired' => 'EXP',
            'promotion' => 'PROM',
        ];

        $prefix = $prefixes[$movementType] ?? 'MOV';
        return $prefix . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function generateNotes($movementType, $productName)
    {
        $notes = [
            'sale' => "Sale of {$productName}",
            'purchase' => "Purchase of {$productName}",
            'adjustment' => "Stock adjustment for {$productName}",
            'transfer_in' => "Transfer in of {$productName}",
            'transfer_out' => "Transfer out of {$productName}",
            'return' => "Customer return of {$productName}",
            'damage' => "Damaged {$productName} - write off",
            'theft' => "Theft/loss of {$productName}",
            'expired' => "Expired {$productName} - disposal",
            'promotion' => "Promotional giveaway of {$productName}",
        ];

        return $notes[$movementType] ?? "Movement of {$productName}";
    }
}