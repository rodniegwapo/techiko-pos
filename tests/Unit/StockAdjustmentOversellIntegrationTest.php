<?php

namespace Tests\Unit;

use App\Models\StockAdjustment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockAdjustmentOversellIntegrationTest extends TestCase
{
    #[Test]
    public function apply_inventory_from_approved_adjustment_is_public(): void
    {
        $method = new \ReflectionMethod(StockAdjustment::class, 'applyInventoryFromApprovedAdjustment');

        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function process_adjustment_items_is_not_public(): void
    {
        $method = new \ReflectionMethod(StockAdjustment::class, 'processAdjustmentItems');

        $this->assertFalse($method->isPublic());
    }
}
