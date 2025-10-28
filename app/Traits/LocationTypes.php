<?php

namespace App\Traits;

trait LocationTypes
{
    /**
     * Get available location types
     */
    public function getLocationTypes()
    {
        return [
            ['label' => 'Store', 'value' => 'store'],
            ['label' => 'Warehouse', 'value' => 'warehouse'],
            ['label' => 'Supplier', 'value' => 'supplier'],
            ['label' => 'Customer', 'value' => 'customer'],
        ];
    }
}
