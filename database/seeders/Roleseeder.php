<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class Roleseeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure core roles exist with correct guard
        Role::findOrCreate('super admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('manager', 'web');
        Role::findOrCreate('supervisor', 'web');
        Role::findOrCreate('cashier', 'web');
    }
}
