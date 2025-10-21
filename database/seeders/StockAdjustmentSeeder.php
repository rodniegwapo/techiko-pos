<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class StockAdjustmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('⚖️ Creating stock adjustments...');
        $this->createStockAdjustments();
        
        $this->command->info('📋 Creating adjustment items...');
        $this->createAdjustmentItems();
        
        $this->command->info('✅ Stock adjustments seeded successfully!');
    }

    /**
     * Create stock adjustments
     */
    private function createStockAdjustments()
    {
        $locations = InventoryLocation::where('is_active', true)->get();
        $users = User::all();
        
        if ($users->isEmpty()) {
            $this->command->warn('No users found. Creating default user...');
            $user = User::create([
                'name' => 'System User',
                'email' => 'system@example.com',
                'password' => bcrypt('password'),
                'domain' => 'default-store',
            ]);
            $users = collect([$user]);
        }

        $adjustmentCount = 0;
        $startDate = now()->subMonths(3);
        $endDate = now();

        foreach ($locations as $location) {
            // Create 2-8 adjustments per location over 3 months
            $adjustmentsPerLocation = rand(2, 8);
            
            for ($i = 0; $i < $adjustmentsPerLocation; $i++) {
                $adjustmentDate = Carbon::createFromTimestamp(
                    rand($startDate->timestamp, $endDate->timestamp)
                );
                
                $adjustmentTypes = [
                    'physical_count' => 'Physical inventory count',
                    'damage' => 'Damage assessment',
                    'theft' => 'Theft investigation',
                    'expiry' => 'Expired products removal',
                    'quality_control' => 'Quality control check',
                    'audit' => 'Audit adjustment',
                ];
                
                $type = array_rand($adjustmentTypes);
                $statuses = ['pending', 'approved', 'completed', 'rejected'];
                $status = $statuses[rand(0, 2)]; // Exclude 'rejected' for seed data
                
                $adjustment = StockAdjustment::create([
                    'adjustment_number' => 'ADJ-' . now()->format('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT) . '-' . uniqid(),
                    'location_id' => $location->id,
                    'domain' => $location->domain,
                    'type' => $this->mapAdjustmentType($type),
                    'reason' => $this->mapAdjustmentReason($type),
                    'description' => $this->generateAdjustmentNotes($type),
                    'total_value_change' => rand(-500, 500),
                    'status' => $this->mapStatus($status),
                    'created_by' => $users->random()->id,
                    'approved_by' => $status === 'approved' || $status === 'completed' ? $users->random()->id : null,
                    'approved_at' => $status === 'approved' || $status === 'completed' ? $adjustmentDate->addHours(rand(1, 24)) : null,
                    'created_at' => $adjustmentDate,
                    'updated_at' => $adjustmentDate,
                ]);
                
                $adjustmentCount++;
            }
        }

        $this->command->info("Created {$adjustmentCount} stock adjustments");
    }

    /**
     * Create adjustment items
     */
    private function createAdjustmentItems()
    {
        $adjustments = StockAdjustment::all();
        $products = Product::where('track_inventory', true)->get();
        
        if ($adjustments->isEmpty() || $products->isEmpty()) {
            $this->command->warn('No adjustments or products found.');
            return;
        }

        $itemCount = 0;

        foreach ($adjustments as $adjustment) {
            // Create 3-15 items per adjustment
            $itemsPerAdjustment = rand(3, 15);
            $selectedProducts = $products->random($itemsPerAdjustment);
            
            foreach ($selectedProducts as $product) {
                $quantityBefore = rand(10, 100);
                $variance = rand(-10, 10);
                $quantityAfter = max(0, $quantityBefore + $variance);
                $unitCost = $product->cost ?? rand(50, 500);
                
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'system_quantity' => $quantityBefore,
                    'actual_quantity' => $quantityAfter,
                    'adjustment_quantity' => $variance,
                    'unit_cost' => $unitCost,
                    'total_cost_change' => $variance * $unitCost,
                    'batch_number' => $this->generateBatchNumber($adjustment->reason),
                    'expiry_date' => $this->generateExpiryDate(),
                    'notes' => $this->generateItemNotes($adjustment->reason, $variance),
                ]);
                
                $itemCount++;
            }
        }

        $this->command->info("Created {$itemCount} adjustment items");
    }

    /**
     * Generate adjustment notes based on type
     */
    private function generateAdjustmentNotes($type)
    {
        $notes = [
            'physical_count' => [
                'Monthly physical inventory count',
                'Quarterly stock audit',
                'End of month inventory reconciliation',
                'Annual physical count',
            ],
            'damage' => [
                'Damage assessment after storm',
                'Water damage inspection',
                'Handling damage review',
                'Transportation damage assessment',
            ],
            'theft' => [
                'Security incident investigation',
                'Suspected theft review',
                'Missing inventory report',
                'Security audit findings',
            ],
            'expiry' => [
                'Expired products removal',
                'Near expiry date review',
                'Shelf life assessment',
                'Product rotation check',
            ],
            'quality_control' => [
                'Quality control inspection',
                'Product quality assessment',
                'Defective goods review',
                'Quality standards check',
            ],
            'audit' => [
                'Internal audit findings',
                'Compliance audit',
                'Financial audit adjustment',
                'Regulatory audit',
            ],
        ];

        return $notes[$type][array_rand($notes[$type])];
    }

    /**
     * Generate item reason based on adjustment type
     */
    private function generateItemReason($adjustmentType)
    {
        $reasons = [
            'physical_count' => ['Count discrepancy', 'Measurement error', 'Recording error', 'System error'],
            'damage' => ['Water damage', 'Physical damage', 'Handling damage', 'Transport damage'],
            'theft' => ['Suspected theft', 'Missing items', 'Security breach', 'Unauthorized removal'],
            'expiry' => ['Expired product', 'Near expiry', 'Shelf life exceeded', 'Date code issue'],
            'quality_control' => ['Defective item', 'Quality issue', 'Substandard product', 'Contamination'],
            'audit' => ['Audit finding', 'Compliance issue', 'Documentation error', 'Process error'],
        ];

        return $reasons[$adjustmentType][array_rand($reasons[$adjustmentType])];
    }

    /**
     * Generate item notes based on adjustment type and variance
     */
    private function generateItemNotes($adjustmentType, $variance)
    {
        if ($variance > 0) {
            return "Found additional {$variance} units during {$adjustmentType}";
        } elseif ($variance < 0) {
            return "Missing " . abs($variance) . " units during {$adjustmentType}";
        } else {
            return "No variance found during {$adjustmentType}";
        }
    }

    /**
     * Generate batch number based on adjustment type
     */
    private function generateBatchNumber($adjustmentType)
    {
        $prefixes = [
            'physical_count' => 'PC',
            'damage' => 'DMG',
            'theft' => 'THF',
            'expiry' => 'EXP',
            'quality_control' => 'QC',
            'audit' => 'AUD',
        ];

        $prefix = $prefixes[$adjustmentType] ?? 'ADJ';
        return $prefix . '-' . rand(10000, 99999);
    }

    /**
     * Generate expiry date
     */
    private function generateExpiryDate()
    {
        $days = rand(30, 730); // 1 month to 2 years
        return now()->addDays($days);
    }

    /**
     * Map adjustment type to database enum
     */
    private function mapAdjustmentType($type)
    {
        return match ($type) {
            'physical_count' => 'recount',
            'damage' => 'decrease',
            'theft' => 'decrease',
            'expiry' => 'decrease',
            'quality_control' => 'decrease',
            'audit' => 'recount',
            default => 'decrease'
        };
    }

    /**
     * Map adjustment reason to database enum
     */
    private function mapAdjustmentReason($type)
    {
        return match ($type) {
            'physical_count' => 'physical_count',
            'damage' => 'damaged_goods',
            'theft' => 'theft_loss',
            'expiry' => 'expired_goods',
            'quality_control' => 'damaged_goods',
            'audit' => 'physical_count',
            default => 'other'
        };
    }

    /**
     * Map status to database enum
     */
    private function mapStatus($status)
    {
        return match ($status) {
            'pending' => 'pending_approval',
            'approved' => 'approved',
            'completed' => 'approved',
            'rejected' => 'rejected',
            default => 'draft'
        };
    }
}
