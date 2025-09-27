<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LoyaltyTier;
use Spatie\Permission\Models\Role;

class ShowTiers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tiers:show {type?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display all tiers (loyalty and user roles)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->argument('type');

        if (!$type || $type === 'loyalty') {
            $this->showLoyaltyTiers();
        }

        if (!$type || $type === 'roles') {
            $this->showUserRoles();
        }

        return 0;
    }

    private function showLoyaltyTiers()
    {
        $this->info('🎯 LOYALTY TIERS');
        $this->line('');

        $tiers = LoyaltyTier::orderBy('sort_order')->get();
        
        $headers = ['Tier', 'Multiplier', 'Threshold', 'Color', 'Status'];
        $rows = [];

        foreach ($tiers as $tier) {
            $rows[] = [
                $tier->display_name,
                $tier->multiplier . 'x',
                '₱' . number_format($tier->spending_threshold),
                $tier->color,
                $tier->is_active ? '✅ Active' : '❌ Inactive'
            ];
        }

        $this->table($headers, $rows);
        $this->line('');
    }

    private function showUserRoles()
    {
        $this->info('👥 USER ROLES');
        $this->line('');

        $roles = Role::all();
        
        $headers = ['Role', 'Guard', 'Users Count'];
        $rows = [];

        foreach ($roles as $role) {
            $rows[] = [
                ucwords($role->name),
                $role->guard_name,
                $role->users()->count()
            ];
        }

        $this->table($headers, $rows);
        $this->line('');
    }
}