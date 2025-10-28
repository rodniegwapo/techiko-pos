<?php

namespace App\Traits;

trait MovementTypes
{
    /**
     * Get standardized inventory movement type labels
     */
    public function getMovementTypes(): array
    {
        return [
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
    }
}


