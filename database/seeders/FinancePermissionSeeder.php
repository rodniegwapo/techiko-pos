<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * The Finance feature's permissions (Finance pages, Expenses, Profit & Loss, VAT report), for an
 * existing database: php artisan db:seed --class=FinancePermissionSeeder --force
 *
 * Creates any missing permission and gives super admin, admin and manager the ones they lack, like
 * RolePermissionSeeder; it never removes anything. A test keeps ROUTES in step with the routes behind the
 * Finance feature.
 */
class FinancePermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        // Finance pages
        'finance.dashboard',
        'finance.income-statement',
        'finance.cash-flow',
        'finance.balance-sheet',
        'finance.receivables',
        'finance.explain',
        'finance.ask',
        'finance.reviews.index',
        'finance.reviews.generate',
        // Supplier bills
        'finance.payables.index',
        'finance.suppliers.store',
        'finance.suppliers.update',
        'finance.bills.store',
        'finance.bills.update',
        'finance.bills.destroy',
        'finance.bill-payments.store',
        'finance.bill-payments.destroy',
        // Other income
        'finance.other-income.index',
        'finance.other-income.store',
        'finance.other-income.update',
        'finance.other-income.destroy',
        // Assets, loans and balances
        'finance.balance-items.index',
        'finance.accounts.store',
        'finance.accounts.update',
        'finance.account-balances.store',
        'finance.account-balances.destroy',
        'finance.assets.store',
        'finance.assets.update',
        'finance.assets.destroy',
        'finance.loans.store',
        'finance.loans.update',
        'finance.loans.destroy',
        'finance.loan-payments.store',
        'finance.loan-payments.destroy',
        'finance.other-liabilities.store',
        'finance.other-liabilities.update',
        'finance.other-liabilities.destroy',
        'finance.owner-investments.store',
        'finance.owner-investments.update',
        'finance.owner-investments.destroy',
        // Expenses
        'expenses.index',
        'expenses.store',
        'expenses.update',
        'expenses.destroy',
        'expenses.export',
        'expenses.receipt',
        'expenses.categories.store',
        'expenses.categories.update',
        'expenses.categories.destroy',
        'expenses.recurring.store',
        'expenses.recurring.update',
        'expenses.recurring.destroy',
        // Profit & Loss
        'profit-loss.index',
        'profit-loss.export',
        // VAT report
        'vat-report.index',
        'vat-report.export',
        'vat-report.export-json',
    ];

    public const ROLES = ['super admin', 'admin', 'manager'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Finance', ['finance', 'expenses', 'profit-loss', 'vat-report'], self::ROUTES, self::ROLES);
    }
}
