<?php

namespace Tests\Unit;

use App\Services\Finance\FinancialInsightService;
use PHPUnit\Framework\TestCase;

class FinancialInsightServiceTest extends TestCase
{
    /** A month (30 days) of figures, with the parts a test cares about overridden. */
    private function overview(array $overrides = []): array
    {
        $base = [
            'period' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
            'current' => [
                'revenue' => 100000, 'cogs' => 60000, 'gross_profit' => 40000, 'gross_margin_pct' => 40.0, 'items_missing_cost' => 0,
                'operating_expenses' => 20000, 'other_expenses' => 0, 'other_income' => 0, 'net_profit' => 20000, 'net_margin_pct' => 20.0,
            ],
            'previous' => [
                'revenue' => 100000, 'cogs' => 60000, 'gross_profit' => 40000, 'gross_margin_pct' => 40.0, 'items_missing_cost' => 0,
                'operating_expenses' => 20000, 'other_expenses' => 0, 'other_income' => 0, 'net_profit' => 20000, 'net_margin_pct' => 20.0,
            ],
            'changes' => [
                'revenue' => ['amount' => 0, 'pct' => 0.0],
                'gross_profit' => ['amount' => 0, 'pct' => 0.0],
                'operating_expenses' => ['amount' => 0, 'pct' => 0.0],
                'net_profit' => ['amount' => 0, 'pct' => 0.0],
                'gross_margin_pts' => 0.0,
                'net_margin_pts' => 0.0,
                'total_received' => ['amount' => 0, 'pct' => 0.0],
            ],
            'expenses_recorded' => true,
            'expense_breakdown' => [['category_id' => 1, 'name' => 'Rent', 'type' => 'operating', 'amount' => 20000]],
            'previous_expense_breakdown' => [['category_id' => 1, 'name' => 'Rent', 'type' => 'operating', 'amount' => 20000]],
            'payables' => ['outstanding' => 0, 'overdue' => 0, 'due_within_7_days' => 0],
            'money_in' => ['total_received' => 110000],
            'previous_money_in' => ['total_received' => 110000],
            'inventory_value' => 60000,
            'receivables' => [
                'outstanding' => 10000, 'overdue' => 0, 'overdue_pct' => 0.0, 'top_three_share_pct' => 40.0,
                'overdue_customers' => [], 'period' => ['new_credit' => 5000, 'collected' => 5000],
            ],
            'products' => ['low_margin' => []],
        ];

        return array_replace_recursive($base, $overrides);
    }

    private function statusOf(array $health, string $key): string
    {
        return collect($health)->firstWhere('key', $key)['status'];
    }

    public function test_steady_business_reads_stable_and_has_nothing_to_suggest(): void
    {
        $service = new FinancialInsightService;
        $overview = $this->overview();

        $health = $service->health($overview);

        $this->assertSame('Stable', $this->statusOf($health, 'sales'));
        $this->assertSame('Stable', $this->statusOf($health, 'profit'));
        $this->assertSame('Healthy', $this->statusOf($health, 'inventory')); // ₱60k stock ÷ ₱2k/day = 30 days
        $this->assertSame('Stable', $this->statusOf($health, 'expenses'));
        $this->assertSame('Nothing owed', $this->statusOf($health, 'suppliers'));
        $this->assertSame([], $service->recommendations($overview));
    }

    public function test_without_expenses_profit_is_judged_on_gross_profit_and_recording_is_suggested(): void
    {
        $service = new FinancialInsightService;
        $overview = $this->overview(['expenses_recorded' => false]);

        $health = collect($service->health($overview))->keyBy('key');
        $this->assertSame('Gross profit', $health['profit']['label']);
        $this->assertSame('Not recorded yet', $health['expenses']['status']);
        $this->assertSame(['record_expenses'], array_column($service->recommendations($overview), 'key'));
    }

    public function test_sales_up_but_margin_down_flags_profit_and_suggests_checking_costs(): void
    {
        $service = new FinancialInsightService;
        $overview = $this->overview([
            'current' => ['revenue' => 120000, 'gross_profit' => 42000, 'gross_margin_pct' => 35.0, 'net_profit' => 22000, 'net_margin_pct' => 18.3],
            'changes' => [
                'revenue' => ['amount' => 20000, 'pct' => 20.0],
                'gross_profit' => ['amount' => 2000, 'pct' => 5.0],
                'net_profit' => ['amount' => 2000, 'pct' => 10.0],
                'gross_margin_pts' => -5.0,
                'net_margin_pts' => -1.7,
            ],
        ]);

        $health = $service->health($overview);
        $this->assertSame('Improving', $this->statusOf($health, 'sales'));
        $this->assertSame('Improving', $this->statusOf($health, 'profit'));

        $first = $service->recommendations($overview)[0];
        $this->assertSame('margin_drop', $first['key']);
        $this->assertStringContainsString('35%', $first['detail']);
    }

    public function test_overdue_credit_and_slow_stock_lead_the_suggestions(): void
    {
        $service = new FinancialInsightService;
        $overview = $this->overview([
            'inventory_value' => 300000, // 150 days of sales
            'receivables' => [
                'outstanding' => 35000, 'overdue' => 8500, 'overdue_pct' => 24.3, 'top_three_share_pct' => 60.0,
                'overdue_customers' => [['overdue' => 5000], ['overdue' => 3500]],
                'period' => ['new_credit' => 12000, 'collected' => 4000],
            ],
            'products' => ['low_margin' => [['name' => 'Sugar 1kg', 'margin_pct' => 6.0]]],
        ]);

        $health = $service->health($overview);
        $this->assertSame('High', $this->statusOf($health, 'inventory'));
        $this->assertSame('Needs attention', $this->statusOf($health, 'credit'));

        $keys = array_column($service->recommendations($overview), 'key');
        $this->assertSame('collect_overdue', $keys[0]);
        $this->assertContains('high_inventory', $keys);
        $this->assertContains('low_margin_products', $keys);
        $this->assertContains('credit_concentration', $keys);
    }

    public function test_a_net_loss_and_late_supplier_bills_come_first(): void
    {
        $service = new FinancialInsightService;
        $overview = $this->overview([
            'current' => ['operating_expenses' => 50000, 'net_profit' => -10000, 'net_margin_pct' => -10.0],
            'payables' => ['outstanding' => 30000, 'overdue' => 12000, 'due_within_7_days' => 8000],
        ]);

        $health = $service->health($overview);
        $this->assertSame('Loss', $this->statusOf($health, 'profit'));
        $this->assertSame('Overdue bills', $this->statusOf($health, 'suppliers'));

        $keys = array_column($service->recommendations($overview), 'key');
        $this->assertSame(['net_loss', 'pay_overdue_bills'], array_slice($keys, 0, 2));
        $this->assertContains('bills_due_soon', $keys);
    }

    public function test_expenses_growing_faster_than_sales_name_the_category_that_grew(): void
    {
        $service = new FinancialInsightService;
        $overview = $this->overview([
            'current' => ['operating_expenses' => 30000],
            'changes' => ['operating_expenses' => ['amount' => 10000, 'pct' => 50.0], 'revenue' => ['amount' => 2000, 'pct' => 2.0]],
            'expense_breakdown' => [
                ['category_id' => 1, 'name' => 'Rent', 'type' => 'operating', 'amount' => 20000],
                ['category_id' => 2, 'name' => 'Utilities', 'type' => 'operating', 'amount' => 10000],
            ],
        ]);

        $this->assertSame('Increasing', $this->statusOf($service->health($overview), 'expenses'));

        $rec = collect($service->recommendations($overview))->firstWhere('key', 'expenses_rising');
        $this->assertNotNull($rec);
        $this->assertStringContainsString('Utilities', $rec['detail']);
    }

    public function test_peso_formatting(): void
    {
        $this->assertSame('₱1,234.50', FinancialInsightService::peso(1234.5));
        $this->assertSame('-₱20.00', FinancialInsightService::peso(-20));
    }
}
