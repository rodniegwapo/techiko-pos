<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions based on actual route names from ->name() method calls
        $permissions = [
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',

            // AI assistant (chat drawer)
            'assistant.ask',
            'assistant.actions.confirm',
            'assistant.actions.cancel',

            // User Management
            'users.index',
            'users.hierarchy',
            'users.show',
            'users.edit',
            'users.assign-supervisor',
            'users.remove-supervisor',
            'users.supervisor-history',
            'supervisors.available',
            'supervisors.available-for-user',
            'supervisors.auto-assign',
            'supervisors.cascading-options',
            'supervisors.cascading-assign',
            'users.store',
            'users.store',
            'users.show',
            'users.update',
            'users.destroy',
            'users.roles',
            'users.toggle-status',
            'users.verify-email',
            'users.pin.update',

            // Sales
            'sales.index',
            'sales.offline-transactions',
            'sales.offline-sync',
            'sales.products',
            'sales.offline-catalog',
            'settings.index',
            'settings.update',
            'payment-card-types.index',
            'payment-card-types.list',
            'payment-card-types.store',
            'payment-card-types.update',
            'payment-card-types.destroy',
            'payment-card-types.money',
            'wallet-cash-ledger.index',
            'wallet.money-movement',
            'wallet-cash-ledger.store',
            'wallet-cash-ledger.opening-cash.store',
            'wallet-cash-ledger.counted-cash.store',
            'wallet-cash-ledger.end-shift',
            'wallet-cash-ledger.reopen-shift',
            // Cashier shifts and Counted money
            'register-shifts.start',
            'register-shifts.end',
            'register-shifts.reopen',
            'register-shifts.spot-check',
            'register-shifts.index',
            'register-shifts.approve',
            'register-shifts.money-by-user',
            // Cash on hand: owners and admins only
            'cash-on-hand.index',
            'cash-on-hand.store',
            'cash-on-hand.collect',
            'vat-report.index',
            'vat-report.export',
            'vat-report.export-json',
            'finance.dashboard',
            'finance.income-statement',
            'finance.receivables',
            'finance.explain',
            'finance.cash-flow',
            'finance.balance-sheet',
            'finance.other-income.index',
            'finance.other-income.store',
            'finance.other-income.update',
            'finance.other-income.destroy',
            'finance.payables.index',
            'finance.suppliers.store',
            'finance.suppliers.update',
            'finance.bills.store',
            'finance.bills.update',
            'finance.bills.destroy',
            'finance.bill-payments.store',
            'finance.bill-payments.destroy',
            'finance.balance-items.index',
            'finance.accounts.store',
            'finance.accounts.update',
            'finance.account-balances.store',
            'finance.account-balances.destroy',
            'finance.loans.store',
            'finance.loans.update',
            'finance.loans.destroy',
            'finance.loan-payments.store',
            'finance.loan-payments.destroy',
            'finance.assets.store',
            'finance.assets.update',
            'finance.assets.destroy',
            'finance.owner-investments.store',
            'finance.owner-investments.update',
            'finance.owner-investments.destroy',
            'finance.other-liabilities.store',
            'finance.other-liabilities.update',
            'finance.other-liabilities.destroy',
            'finance.ask',
            'finance.reviews.index',
            'finance.reviews.generate',
            'sales-history.export',
            'sales-import.index',
            'sales-import.template',
            'sales-import.preview',
            'sales-import.store',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales-history.index',
            'sales-history.show',
            'purchase-orders.index',
            'purchase-orders.store',
            'purchase-orders.update',
            'purchase-orders.order',
            'purchase-orders.receive',
            'purchase-orders.cancel',
            'timesheets.index',
            'timesheets.export',
            'timesheets.store',
            'timesheets.update',
            'timesheets.punch',
            'tabs.index',
            'tabs.store',
            'tabs.leave',
            'tabs.update',
            'tabs.select',
            'tabs.move',
            'tabs.merge',
            'tabs.split',
            'tabs.cancel',
            'tabs.send',
            'tabs.tickets',
            'tabs.ready',
            'dining-tables.index',
            'dining-tables.store',
            'dining-tables.settings',
            'dining-tables.layout',
            'dining-tables.update',
            'dining-tables.destroy',
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
            'profit-loss.index',
            'profit-loss.export',
            'sales-history.void',
            'sales.loyalty-redemption',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.oversell.statistics',
            'sales.sales.testOrderEvent',
            'sales.items.discount.apply',
            'sales.items.discount.remove',

            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',

            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            'sales.discounts.remove',

            // Current Pending Sale
            'sales.current-pending',

            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',

            // Products
            'products.index',
            'products.modifier-groups.index',
            'products.store',
            'products.update',
            'products.modifier-groups.store',
            'products.modifier-groups.update',
            'products.modifier-groups.destroy',
            'products.destroy',
            'products.create',
            'products.edit',
            'products.assignable',
            'products.attach-location',

            'shared-catalog.lookup',

            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.destroy',
            'categories.create',
            'categories.edit',

            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',

            // Discounts
            'products.discounts.index',
            'products.discounts.store',
            'products.discounts.update',
            'products.discounts.destroy',
            'products.discounts.create',
            'products.discounts.edit',

            // Mandatory Discounts
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            'mandatory-discounts.create',
            'mandatory-discounts.edit',

            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.store',
            'loyalty.tiers.show',
            'loyalty.tiers.update',
            'loyalty.tiers.destroy',

            // Credit Management
            'credits.index',
            'credits.overdue',
            'credits.show',
            'credits.outstanding-invoices',
            'credits.transactions.store',
            'credits.transactions.update',
            'credits.history',
            'credits.settings.update',

            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjustment-products',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.create',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.destroy',
            'inventory.adjustments.edit',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.create',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.edit',
            'inventory.locations.set-default',
            'inventory.locations.switch',
            'inventory.locations.toggle-status',

            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.by-domain',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            'inventory.search.locations',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',

            // Void Logs
            'voids.index',

            // Terminal Setup
            'setup.terminal',

            // Role Management (super user only)
            'roles.index',
            'roles.create',
            'roles.store',
            'roles.show',
            'roles.edit',
            'roles.update',
            'roles.destroy',
            'roles.permission-matrix',

            // Permission Management (super user only)
            'permissions.index',
            'permissions.store',
            'permissions.show',
            'permissions.update',
            'permissions.destroy',
            'permissions.deactivate',
            'permissions.activate',

            // Orders
            'orders.view',
            'orders.recent-pending',

            // Customer Order
            'customer-order',

            // Shared catalog (domain lookup + super catalog UI)
            'catalog.shared-products.index',
            'catalog.shared-products.store',
            'catalog.shared-products.update',
            'catalog.shared-products.destroy',
            'catalog.shared-product-suggestions.index',
            'catalog.shared-product-suggestions.accept',
            'catalog.shared-product-suggestions.reject',

            // Domain servicing billing (tenant UI)
            'billing.servicing.index',
            'billing.servicing.manual_gcash',
            'ai-usage.index',

            // Global manual payment review (super)
            'billing.manual-payments.index',
            'billing.manual-payments.approve',
            'billing.manual-payments.reject',
        ];

        // Get all permission modules
        $modules = DB::table('permission_modules')->get()->keyBy('name');

        foreach ($permissions as $permission) {
            $parts = explode('.', $permission);
            $moduleName = $parts[0];
            $action = $parts[count($parts) - 1]; // Get the last part as action

            // Get module ID
            $module = $modules->get($moduleName);
            $moduleId = $module ? $module->id : null;

            // Generate display name (just the action)
            $displayName = $this->getActionDisplayName($action);

            // Use just the action name since module is already shown as section header
            $uniqueName = $displayName;

            // If we already have a permission with this name, make it unique by adding route info
            $existingPermission = Permission::where('name', $uniqueName)->where('guard_name', 'web')->first();
            if ($existingPermission) {
                $uniqueName = $uniqueName.' ('.$permission.')';
            }

            Permission::firstOrCreate([
                'route_name' => $permission, // Technical route: "users.index"
                'guard_name' => 'web',
            ], [
                'name' => $uniqueName, // "Users - View", "Products - Create"
                'action' => $action, // Action: "index", "create", "edit"
                'module_id' => $moduleId, // Foreign key to permission_modules
            ]);
        }

        // Create roles and assign permissions

        // Super Admin - Full access
        $superAdmin = Role::findOrCreate('super admin', 'web');
        $superAdmin->update(['level' => 1, 'description' => 'Full system access with all permissions']);
        $superAdmin->syncPermissions(Permission::all()->pluck('name')->toArray());

        // Admin - Most permissions except system settings
        $admin = Role::findOrCreate('admin', 'web');
        $admin->update(['level' => 2, 'description' => 'Administrative access with user management capabilities']);
        $admin->syncPermissions($this->getPermissionNamesByRoutes([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',

            // AI assistant (chat drawer)
            'assistant.ask',
            'assistant.actions.confirm',
            'assistant.actions.cancel',

            // User Management
            'users.index',
            'users.hierarchy',
            'users.show',
            'users.edit',
            'users.assign-supervisor',
            'users.remove-supervisor',
            'users.supervisor-history',
            'supervisors.available',
            'supervisors.available-for-user',
            'supervisors.auto-assign',
            'supervisors.cascading-options',
            'supervisors.cascading-assign',
            'users.store',
            'users.store',
            'users.show',
            'users.update',
            'users.destroy',
            'users.roles',
            'users.toggle-status',
            'users.verify-email',
            'users.pin.update',

            // Sales
            'sales.index',
            'sales.offline-transactions',
            'sales.offline-sync',
            'sales.products',
            'sales.offline-catalog',
            'settings.index',
            'settings.update',
            'billing.servicing.index',
            'billing.servicing.manual_gcash',
            'ai-usage.index',
            'payment-card-types.index',
            'payment-card-types.list',
            'payment-card-types.store',
            'payment-card-types.update',
            'payment-card-types.destroy',
            'payment-card-types.money',
            'wallet-cash-ledger.index',
            'wallet.money-movement',
            'wallet-cash-ledger.store',
            'wallet-cash-ledger.opening-cash.store',
            'wallet-cash-ledger.counted-cash.store',
            'wallet-cash-ledger.end-shift',
            'wallet-cash-ledger.reopen-shift',
            // Cashier shifts and Counted money
            'register-shifts.start',
            'register-shifts.end',
            'register-shifts.reopen',
            'register-shifts.spot-check',
            'register-shifts.index',
            'register-shifts.approve',
            'register-shifts.money-by-user',
            // Cash on hand: owners and admins only
            'cash-on-hand.index',
            'cash-on-hand.store',
            'cash-on-hand.collect',
            'vat-report.index',
            'vat-report.export',
            'vat-report.export-json',
            'finance.dashboard',
            'finance.income-statement',
            'finance.receivables',
            'finance.explain',
            'finance.cash-flow',
            'finance.balance-sheet',
            'finance.other-income.index',
            'finance.other-income.store',
            'finance.other-income.update',
            'finance.other-income.destroy',
            'finance.payables.index',
            'finance.suppliers.store',
            'finance.suppliers.update',
            'finance.bills.store',
            'finance.bills.update',
            'finance.bills.destroy',
            'finance.bill-payments.store',
            'finance.bill-payments.destroy',
            'finance.balance-items.index',
            'finance.accounts.store',
            'finance.accounts.update',
            'finance.account-balances.store',
            'finance.account-balances.destroy',
            'finance.loans.store',
            'finance.loans.update',
            'finance.loans.destroy',
            'finance.loan-payments.store',
            'finance.loan-payments.destroy',
            'finance.assets.store',
            'finance.assets.update',
            'finance.assets.destroy',
            'finance.owner-investments.store',
            'finance.owner-investments.update',
            'finance.owner-investments.destroy',
            'finance.other-liabilities.store',
            'finance.other-liabilities.update',
            'finance.other-liabilities.destroy',
            'finance.ask',
            'finance.reviews.index',
            'finance.reviews.generate',
            'sales-history.export',
            'sales-import.index',
            'sales-import.template',
            'sales-import.preview',
            'sales-import.store',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales-history.index',
            'sales-history.show',
            'purchase-orders.index',
            'purchase-orders.store',
            'purchase-orders.update',
            'purchase-orders.order',
            'purchase-orders.receive',
            'purchase-orders.cancel',
            'timesheets.index',
            'timesheets.export',
            'timesheets.store',
            'timesheets.update',
            'timesheets.punch',
            'tabs.index',
            'tabs.store',
            'tabs.leave',
            'tabs.update',
            'tabs.select',
            'tabs.move',
            'tabs.merge',
            'tabs.split',
            'tabs.cancel',
            'tabs.send',
            'tabs.tickets',
            'tabs.ready',
            'dining-tables.index',
            'dining-tables.store',
            'dining-tables.settings',
            'dining-tables.layout',
            'dining-tables.update',
            'dining-tables.destroy',
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
            'profit-loss.index',
            'profit-loss.export',
            'sales-history.void',
            'sales.loyalty-redemption',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.oversell.statistics',
            'sales.items.discount.apply',
            'sales.items.discount.remove',

            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',

            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            'sales.discounts.remove',

            // Current Pending Sale
            'sales.current-pending',

            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',

            // Products
            'products.index',
            'products.modifier-groups.index',
            'products.store',
            'products.update',
            'products.modifier-groups.store',
            'products.modifier-groups.update',
            'products.modifier-groups.destroy',
            'products.destroy',
            'products.create',
            'products.edit',
            'products.assignable',
            'products.attach-location',

            'shared-catalog.lookup',

            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.destroy',
            'categories.create',
            'categories.edit',

            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',

            // Discounts
            'products.discounts.index',
            'products.discounts.store',
            'products.discounts.update',
            'products.discounts.destroy',
            'products.discounts.create',
            'products.discounts.edit',

            // Mandatory Discounts
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            'mandatory-discounts.create',
            'mandatory-discounts.edit',

            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.store',
            'loyalty.tiers.show',
            'loyalty.tiers.update',
            'loyalty.tiers.destroy',

            // Credit Management
            'credits.index',
            'credits.overdue',
            'credits.show',
            'credits.outstanding-invoices',
            'credits.transactions.store',
            'credits.transactions.update',
            'credits.history',
            'credits.settings.update',

            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjustment-products',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.create',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.destroy',
            'inventory.adjustments.edit',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.create',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.edit',
            'inventory.locations.set-default',
            'inventory.locations.switch',
            'inventory.locations.toggle-status',

            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.by-domain',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            'inventory.search.locations',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',

            // Void Logs
            'voids.index',

            // Terminal Setup
            'setup.terminal',

            // Orders
            'orders.view',
            'orders.recent-pending',

            // Customer Order
            'customer-order',
        ]));

        // Manager - Operational permissions
        $manager = Role::findOrCreate('manager', 'web');
        $manager->update(['level' => 3, 'description' => 'Operational management with reporting and staff oversight']);
        $manager->syncPermissions($this->getPermissionNamesByRoutes([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',

            // AI assistant (chat drawer)
            'assistant.ask',
            'assistant.actions.confirm',
            'assistant.actions.cancel',

            // Sales
            'sales.index',
            'sales.offline-transactions',
            'sales.offline-sync',
            'sales.products',
            'sales.offline-catalog',
            'settings.index',
            'settings.update',
            'billing.servicing.index',
            'billing.servicing.manual_gcash',
            'ai-usage.index',
            'payment-card-types.index',
            'payment-card-types.list',
            'payment-card-types.store',
            'payment-card-types.update',
            'payment-card-types.destroy',
            'payment-card-types.money',
            'wallet-cash-ledger.index',
            'wallet.money-movement',
            'wallet-cash-ledger.store',
            'wallet-cash-ledger.opening-cash.store',
            'wallet-cash-ledger.counted-cash.store',
            'wallet-cash-ledger.end-shift',
            'wallet-cash-ledger.reopen-shift',
            // Cashier shifts and Counted money
            'register-shifts.start',
            'register-shifts.end',
            'register-shifts.reopen',
            'register-shifts.spot-check',
            'register-shifts.index',
            'register-shifts.approve',
            'register-shifts.money-by-user',
            'vat-report.index',
            'vat-report.export',
            'vat-report.export-json',
            'finance.dashboard',
            'finance.income-statement',
            'finance.receivables',
            'finance.explain',
            'finance.cash-flow',
            'finance.balance-sheet',
            'finance.other-income.index',
            'finance.other-income.store',
            'finance.other-income.update',
            'finance.other-income.destroy',
            'finance.payables.index',
            'finance.suppliers.store',
            'finance.suppliers.update',
            'finance.bills.store',
            'finance.bills.update',
            'finance.bills.destroy',
            'finance.bill-payments.store',
            'finance.bill-payments.destroy',
            'finance.balance-items.index',
            'finance.accounts.store',
            'finance.accounts.update',
            'finance.account-balances.store',
            'finance.account-balances.destroy',
            'finance.loans.store',
            'finance.loans.update',
            'finance.loans.destroy',
            'finance.loan-payments.store',
            'finance.loan-payments.destroy',
            'finance.assets.store',
            'finance.assets.update',
            'finance.assets.destroy',
            'finance.owner-investments.store',
            'finance.owner-investments.update',
            'finance.owner-investments.destroy',
            'finance.other-liabilities.store',
            'finance.other-liabilities.update',
            'finance.other-liabilities.destroy',
            'finance.ask',
            'finance.reviews.index',
            'finance.reviews.generate',
            'sales-history.export',
            'sales-import.index',
            'sales-import.template',
            'sales-import.preview',
            'sales-import.store',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales-history.index',
            'sales-history.show',
            'purchase-orders.index',
            'purchase-orders.store',
            'purchase-orders.update',
            'purchase-orders.order',
            'purchase-orders.receive',
            'purchase-orders.cancel',
            'timesheets.index',
            'timesheets.export',
            'timesheets.store',
            'timesheets.update',
            'timesheets.punch',
            'tabs.index',
            'tabs.store',
            'tabs.leave',
            'tabs.update',
            'tabs.select',
            'tabs.move',
            'tabs.merge',
            'tabs.split',
            'tabs.cancel',
            'tabs.send',
            'tabs.tickets',
            'tabs.ready',
            'dining-tables.index',
            'dining-tables.store',
            'dining-tables.settings',
            'dining-tables.layout',
            'dining-tables.update',
            'dining-tables.destroy',
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
            'profit-loss.index',
            'profit-loss.export',
            'sales-history.void',
            'sales.loyalty-redemption',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.oversell.statistics',
            'sales.items.discount.apply',
            'sales.items.discount.remove',

            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',

            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            'sales.discounts.remove',

            // Current Pending Sale
            'sales.current-pending',

            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',

            // Products
            'products.index',
            'products.modifier-groups.index',
            'products.store',
            'products.update',
            'products.modifier-groups.store',
            'products.modifier-groups.update',
            'products.modifier-groups.destroy',
            'products.create',
            'products.edit',
            'products.assignable',
            'products.attach-location',

            'shared-catalog.lookup',

            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.create',
            'categories.edit',

            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',

            // Discounts
            'products.discounts.index',

            // Mandatory Discounts (manager read-only)
            'mandatory-discounts.index',

            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',

            // Credit Management
            'credits.index',
            'credits.overdue',
            'credits.show',
            'credits.outstanding-invoices',
            'credits.transactions.store',
            'credits.transactions.update',
            'credits.history',
            'credits.settings.update',

            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjustment-products',
            'inventory.adjustments.index',
            'inventory.adjustments.show',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.locations.index',
            'inventory.locations.show',

            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.by-domain',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',
            'inventory.adjustments.index',
            'inventory.adjustments.show',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',

            // Void Logs
            'voids.index',

            // Orders
            'orders.view',
            'orders.recent-pending',

            // Customer Order
            'customer-order',
        ]));

        // Supervisor - Shift supervision permissions
        $supervisor = Role::findOrCreate('supervisor', 'web');
        $supervisor->update(['level' => 4, 'description' => 'Shift supervision with limited management capabilities']);
        $supervisor->syncPermissions($this->getPermissionNamesByRoutes([
            // Timeclock: clock in and out with their own PIN
            'timesheets.punch',

            // Dine-in: run tabs and send orders to the kitchen
            'tabs.index',
            'tabs.store',
            'tabs.leave',
            'tabs.update',
            'tabs.select',
            'tabs.move',
            'tabs.merge',
            'tabs.split',
            'tabs.cancel',
            'tabs.send',
            'tabs.tickets',
            'tabs.ready',

            // Reopen a shift from the POS (logged)
            'wallet-cash-ledger.reopen-shift',
            // Today's drawer: their own shift, and View details of who holds what
            'wallet.money-movement',
            // Cashier shifts and Counted money
            'register-shifts.start',
            'register-shifts.end',
            'register-shifts.reopen',
            'register-shifts.spot-check',
            'register-shifts.index',
            'register-shifts.approve',
            'register-shifts.money-by-user',

            // Dashboard
            'dashboard',
            'dashboard.sales-chart',

            // AI assistant (chat drawer)
            'assistant.ask',
            'assistant.actions.confirm',
            'assistant.actions.cancel',

            // User Management (view only)
            'users.index',
            'users.show',

            // Sales
            'sales.index',
            'sales.offline-transactions',
            'sales.offline-sync',
            'sales.products',
            'sales.offline-catalog',
            'payment-card-types.list',
            'payment-card-types.store',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales-history.index',
            'sales-history.show',
            'sales-history.void',
            'sales.loyalty-redemption',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.oversell.statistics',
            'sales.items.discount.apply',
            'sales.items.discount.remove',

            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',

            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            'sales.discounts.remove',

            // Current Pending Sale
            'sales.current-pending',

            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',

            // Products
            'products.index',
            'products.modifier-groups.index',
            'products.store',
            'products.update',
            'products.modifier-groups.store',
            'products.modifier-groups.update',
            'products.modifier-groups.destroy',
            'products.create',
            'products.edit',
            'products.assignable',
            'products.attach-location',

            'shared-catalog.lookup',

            // Categories
            'categories.index',

            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',

            // Discounts
            'products.discounts.index',

            // Mandatory Discounts (view only)
            'mandatory-discounts.index',

            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',

            // Credit Management
            'credits.index',
            'credits.overdue',
            'credits.show',
            'credits.outstanding-invoices',
            'credits.transactions.store',
            'credits.history',

            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.locations.index',
            'inventory.locations.show',

            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.by-domain',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',

            // Void Logs
            'voids.index',

            // Orders
            'orders.view',
            'orders.recent-pending',

            // Customer Order
            'customer-order',
        ]));

        // Cashier - Basic sales permissions
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->update(['level' => 5, 'description' => 'Front-line operations with sales processing capabilities']);
        $cashier->syncPermissions($this->getPermissionNamesByRoutes([
            // Timeclock: clock in and out with their own PIN
            'timesheets.punch',

            // Dine-in: run tabs and send orders to the kitchen
            'tabs.index',
            'tabs.store',
            'tabs.leave',
            'tabs.update',
            'tabs.select',
            'tabs.move',
            'tabs.merge',
            'tabs.split',
            'tabs.cancel',
            'tabs.send',
            'tabs.tickets',
            'tabs.ready',

            // Dashboard
            'dashboard',
            'dashboard.sales-chart',

            // AI assistant (chat drawer)
            'assistant.ask',
            'assistant.actions.confirm',
            'assistant.actions.cancel',

            // Sales
            'sales.index',
            'sales.offline-transactions',
            'sales.offline-sync',
            'sales.products',
            'sales.offline-catalog',
            'payment-card-types.list',
            'payment-card-types.store',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales-history.index',
            'sales-history.show',
            'sales-history.void',
            // Opening today's shift from the POS "no shift open" block
            'wallet-cash-ledger.opening-cash.store',
            // Today's drawer: count the cash and end the shift (they see Expected, not short/over)
            'wallet.money-movement',
            'wallet-cash-ledger.counted-cash.store',
            'wallet-cash-ledger.end-shift',
            // Anyone on the till can reopen a shift (several drawers share a store); every reopen is logged
            'wallet-cash-ledger.reopen-shift',
            // Their own shift: start, end with a count (no PIN), reopen with a reason
            'register-shifts.start',
            'register-shifts.end',
            'register-shifts.reopen',
            'register-shifts.spot-check',
            'sales.loyalty-redemption',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.oversell.statistics',
            'sales.items.discount.apply',
            'sales.items.discount.remove',

            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',

            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            'sales.discounts.remove',

            // Current Pending Sale
            'sales.current-pending',

            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',

            // Products
            'products.index',
            'products.modifier-groups.index',

            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',

            // Discounts
            'products.discounts.index',

            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',

            // Credit Management (view only)
            'credits.index',
            'credits.show',
            'credits.outstanding-invoices',
            'credits.history',

            // Inventory Management (view only)
            'inventory.index',
            'inventory.products',
            'inventory.locations.index',
            'inventory.locations.show',

            // Inventory API
            'inventory.products',
            'inventory.search.products',
            'inventory.locations.by-domain',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',

            // Orders
            'orders.view',
            'orders.recent-pending',

            // Customer Order
            'customer-order',
        ]));

        $this->command->info('Roles and permissions created successfully!');
    }

    /**
     * Get display name for action
     */
    public function getActionDisplayName(string $action): string
    {
        $actionLabels = [
            'index' => 'View',
            'create' => 'Create',
            'store' => 'Create',
            'edit' => 'Edit',
            'update' => 'Update',
            'show' => 'View',
            'destroy' => 'Delete',
            'delete' => 'Delete',
            'apply' => 'Apply',
            'manage' => 'Manage',
            'export' => 'Export',
            'punch' => 'Clock in / out',
            'select' => 'Pick up',
            'leave' => 'Back to tables',
            'move' => 'Move',
            'merge' => 'Merge',
            'split' => 'Split',
            'cancel' => 'Cancel',
            'send' => 'Send to kitchen',
            'tickets' => 'Kitchen tickets',
            'settings' => 'Settings',
            'layout' => 'Floor plan',
            'export-json' => 'Export JSON',
            'dashboard' => 'Dashboard',
            'search' => 'Search',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'submit' => 'Submit',
            'toggle-status' => 'Toggle',
            'set-default' => 'Set Default',
            'switch' => 'Switch Store',
            'hierarchy' => 'Hierarchy',
            'assign-supervisor' => 'Assign Supervisor',
            'remove-supervisor' => 'Remove Supervisor',
            'supervisor-history' => 'Supervisor History',
            'available' => 'Available',
            'available-for-user' => 'Available For User',
            'auto-assign' => 'Auto Assign',
            'cascading-options' => 'Cascading Options',
            'cascading-assign' => 'Cascading Assign',
            'toggle-status' => 'Toggle Status',
            'syncDraft' => 'Sync Draft',
            'syncDraftImmediate' => 'Sync Draft Immediate',
            'find-sale-item' => 'Find Sale Item',
            'assignCustomer' => 'Assign Customer',
            'processLoyalty' => 'Process Loyalty',
            'void' => 'Void',
            'store' => 'Store',
            'products' => 'Products',
            'movements' => 'Movements',
            'low-stock' => 'Low Stock',
            'valuation' => 'Valuation',
            'receive' => 'Receive',
            'transfer' => 'Transfer',
            'adjustment-products' => 'Adjustment Products',
            'adjustments' => 'Adjustments',
            'locations' => 'Locations',
            'summary' => 'Summary',
            'stats' => 'Stats',
            'customers' => 'Customers',
            'analytics' => 'Analytics',
            'adjust-points' => 'Adjust Points',
            'tiers' => 'Tiers',
            'permission-matrix' => 'Permission Matrix',
            'permissions' => 'Permissions',
            'deactivate' => 'Deactivate',
            'activate' => 'Activate',
            'bulk-deactivate' => 'Bulk Deactivate',
            'grouped' => 'Grouped',
            'view' => 'View',
            'recent-pending' => 'Recent Pending',
            'customer-order' => 'Customer Order',

            // Cart Management Actions
            'cart.add' => 'Add to Cart',
            'cart.remove' => 'Remove from Cart',
            'cart.update-quantity' => 'Update Quantity',
            'cart.state' => 'View Cart State',

            // Discount Management Actions
            'discounts.current' => 'View Current Discounts',
            'discounts.sale' => 'View Sale Discounts',
            'discounts.update' => 'Update Discounts',
        ];

        return $actionLabels[$action] ?? ucfirst($action);
    }

    /**
     * Get permission names by route names
     */
    private function getPermissionNamesByRoutes(array $routeNames): array
    {
        return Permission::whereIn('route_name', $routeNames)
            ->pluck('name')
            ->toArray();
    }
}
