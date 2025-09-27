<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\LoyaltyTier;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🎯 Seeding Loyalty Tiers...');
        $this->seedLoyaltyTiers();
        
        $this->command->info('👥 Seeding User Roles...');
        $this->seedUserRoles();
        
        $this->command->info('✅ All tiers seeded successfully!');
    }

    /**
     * Seed loyalty tiers
     */
    private function seedLoyaltyTiers(): void
    {
        $loyaltyTiers = [
            [
                'name' => 'bronze',
                'display_name' => 'Bronze',
                'multiplier' => 1.00,
                'spending_threshold' => 0,
                'color' => '#CD7F32',
                'description' => 'Welcome tier with standard benefits and 1x points',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'silver',
                'display_name' => 'Silver',
                'multiplier' => 1.25,
                'spending_threshold' => 20000,
                'color' => '#C0C0C0',
                'description' => '25% bonus points, priority support, and exclusive offers',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'gold',
                'display_name' => 'Gold',
                'multiplier' => 1.50,
                'spending_threshold' => 50000,
                'color' => '#FFD700',
                'description' => '50% bonus points, exclusive offers, and early access to sales',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'platinum',
                'display_name' => 'Platinum',
                'multiplier' => 2.00,
                'spending_threshold' => 100000,
                'color' => '#E5E4E2',
                'description' => 'Double points, VIP treatment, personal shopping assistance',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'diamond',
                'display_name' => 'Diamond',
                'multiplier' => 2.50,
                'spending_threshold' => 250000,
                'color' => '#B9F2FF',
                'description' => 'Ultimate tier with 2.5x points, concierge service, and exclusive events',
                'sort_order' => 5,
                'is_active' => false, // Optional tier
            ],
        ];

        foreach ($loyaltyTiers as $tier) {
            LoyaltyTier::updateOrCreate(
                ['name' => $tier['name']],
                $tier
            );
            $this->command->line("  ✓ {$tier['display_name']} tier created/updated");
        }
    }

    /**
     * Seed user roles and their hierarchy
     */
    private function seedUserRoles(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $userRoles = [
            [
                'name' => 'super admin',
                'display_name' => 'Super Administrator',
                'description' => 'Full system access with all permissions',
                'level' => 1,
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrator', 
                'description' => 'Administrative access with user management capabilities',
                'level' => 2,
            ],
            [
                'name' => 'manager',
                'display_name' => 'Manager',
                'description' => 'Operational management with reporting and staff oversight',
                'level' => 3,
            ],
            [
                'name' => 'supervisor',
                'display_name' => 'Supervisor',
                'description' => 'Shift supervision with limited management capabilities',
                'level' => 4,
            ],
            [
                'name' => 'cashier',
                'display_name' => 'Cashier',
                'description' => 'Front-line operations with sales processing capabilities',
                'level' => 5,
            ],
        ];

        foreach ($userRoles as $roleData) {
            $role = Role::updateOrCreate(
                ['name' => $roleData['name']],
                [
                    'name' => $roleData['name'],
                    'guard_name' => 'web'
                ]
            );
            $this->command->line("  ✓ {$roleData['display_name']} role created/updated");
        }

        $this->command->info('📋 Role hierarchy established:');
        $this->command->line('  1. Super Admin (Full Access)');
        $this->command->line('  2. Admin (User Management)');
        $this->command->line('  3. Manager (Operations)');
        $this->command->line('  4. Supervisor (Shift Management)');
        $this->command->line('  5. Cashier (Sales Only)');
    }
}