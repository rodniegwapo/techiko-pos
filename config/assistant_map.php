<?php

/*
|--------------------------------------------------------------------------
| App map for the AI assistant
|--------------------------------------------------------------------------
|
| Every page of an organization's sidebar: where it is and what it is for. The assistant gets
| the pages the user can open (by permission and feature) so it can answer "where do I…" and
| "what is this page for" anywhere in the app. Keep it in step with
| resources/js/Components/sidebar/leftMenu.vue; a test fails when a sidebar page is missing.
|
| route: route name without "domains."  ·  feature: an organization feature the page needs
|
*/

return [
    ['route' => 'dashboard', 'path' => 'Dashboard', 'about' => "today's sales, sales chart and quick totals for the store"],

    ['route' => 'sales.index', 'path' => 'Sales', 'about' => 'the POS screen: add products to the order, apply discounts, pick a customer and take payment (Classic or Modern layout)'],
    ['route' => 'sales.offline-transactions', 'path' => 'Offline sales', 'about' => 'sales made without internet that are waiting to sync, and syncing them'],
    ['route' => 'sales-history.index', 'path' => 'Sales History', 'about' => 'past sales and receipts: search, view a sale, export, and void a completed sale'],

    ['route' => 'finance.dashboard', 'path' => 'Finance → Overview', 'about' => 'business health at a glance: sales, profit, money in and out, with AI explanations and "Ask about your business"', 'feature' => 'finance'],
    ['route' => 'profit-loss.index', 'path' => 'Finance → Profit & loss', 'about' => 'profit and loss for a period: sales, cost of goods, gross profit, expenses and net profit; can be exported', 'feature' => 'finance'],
    ['route' => 'finance.cash-flow', 'path' => 'Finance → Money in & out', 'about' => 'money that came in and went out for a period, and why it differs from profit', 'feature' => 'finance'],
    ['route' => 'finance.balance-sheet', 'path' => 'Finance → What you own & owe', 'about' => 'what the business owns and owes today, and the owner\'s share', 'feature' => 'finance'],
    ['route' => 'finance.receivables', 'path' => "Finance → Customers' utang", 'about' => 'what customers owe the business, overdue amounts and collections', 'feature' => 'finance'],
    ['route' => 'finance.payables.index', 'path' => 'Finance → Bills to pay', 'about' => 'suppliers, their bills, due dates and recording bill payments', 'feature' => 'finance'],
    ['route' => 'expenses.index', 'path' => 'Finance → Expenses', 'about' => 'record expenses (rent, electricity, salaries…), expense categories, recurring expenses and receipts; can be exported', 'feature' => 'finance'],
    ['route' => 'finance.other-income.index', 'path' => 'Finance → Other income', 'about' => 'income that is not from sales, e.g. rent received or interest', 'feature' => 'finance'],
    ['route' => 'finance.balance-items.index', 'path' => 'Finance → Bank, loans & equipment', 'about' => 'bank and e-wallet balances, loans and repayments, equipment and other assets, owner investments and other amounts owed', 'feature' => 'finance'],
    ['route' => 'finance.reviews.index', 'path' => 'Finance → Monthly review', 'about' => 'a review of each month compared with the month before: what went well, what needs attention, suggested actions', 'feature' => 'finance'],
    ['route' => 'vat-report.index', 'path' => 'Finance → VAT report', 'about' => 'VAT collected on sales for a period, with export', 'feature' => 'finance'],

    ['route' => 'wallet.drawer', 'path' => "Cash & wallet → Today's drawer", 'about' => 'the cash drawer today: opening cash + cash sales + cash in − cash out = expected; counting the cash (short or over), ending and reopening the shift'],
    ['route' => 'wallet.cash-in-out', 'path' => 'Cash & wallet → Cash in & out', 'about' => 'every line in and out of the cash drawer (float added, owner took cash, corrections, expenses and suppliers paid in cash) and money moved to or from GCash, a bank or a card terminal'],
    ['route' => 'wallet.balances', 'path' => 'Cash & wallet → Where money is', 'about' => 'how much is in the cash drawer and in each payment channel (GCash, bank, card terminal), setting a channel to its actual balance, and every peso in and out (All money)'],
    ['route' => 'payment-card-types.index', 'path' => 'Cash & wallet → Where money is → Manage channels', 'about' => 'payment methods accepted besides cash (cards, GCash, Maya…) and the money received through each'],
    ['route' => 'wallet.history', 'path' => 'Cash & wallet → Drawer history', 'about' => "past counts, changes to opening cash, a check of the drawer since its last count, and the store's ended shifts"],

    ['route' => 'products.index', 'path' => 'Products → Items', 'about' => 'the product list for the current store: create, edit, add an existing product to the store, modifiers; stock status shown'],
    ['route' => 'categories.index', 'path' => 'Products → Categories', 'about' => 'product categories used to group items on the POS and in reports'],
    ['route' => 'products.discounts.index', 'path' => 'Products → Discounts', 'about' => 'discounts that can be applied to products or whole orders at the POS'],
    ['route' => 'mandatory-discounts.index', 'path' => 'Products → Mandatory Discounts', 'about' => 'discounts required by law, like PWD and Senior Citizen, and their rates'],

    ['route' => 'inventory.index', 'path' => 'Inventory → Dashboard', 'about' => 'stock overview: stock value, low-stock and out-of-stock items, recent movements'],
    ['route' => 'inventory.products', 'path' => 'Inventory → Products', 'about' => 'stock levels per product in the current store, reorder levels, receiving and transferring stock'],
    ['route' => 'inventory.movements', 'path' => 'Inventory → Movements', 'about' => 'every stock change (sales, receipts, transfers, adjustments) with who and when'],
    ['route' => 'inventory.adjustments.index', 'path' => 'Inventory → Stock Adjustments', 'about' => 'correct stock counts (damaged, lost, recount); an adjustment is a draft until it is submitted and approved'],
    ['route' => 'inventory.locations.index', 'path' => 'Inventory → Locations', 'about' => 'the stores and warehouses: add, edit, activate or deactivate, and set the default store'],
    ['route' => 'inventory.valuation', 'path' => 'Inventory → Valuation Report', 'about' => 'the value of stock on hand at cost, by product and store'],
    ['route' => 'purchase-orders.index', 'path' => 'Purchasing', 'about' => 'purchase orders to suppliers: create, send, receive deliveries (stock comes in and the supplier bill is updated), cancel'],

    ['route' => 'loyalty.index', 'path' => 'Loyalty Program', 'about' => 'loyalty points and tiers for customers, adjusting points and loyalty analytics'],
    ['route' => 'voids.index', 'path' => 'Void Logs', 'about' => 'voided items and sales, with who voided them and why'],
    ['route' => 'customers.index', 'path' => 'Customers', 'about' => 'the customer list: add and edit customers'],
    ['route' => 'credits.index', 'path' => 'Credit Management', 'about' => 'customer credit accounts: credit limits and terms, balances, overdue accounts and recording payments'],
    ['route' => 'users.index', 'path' => 'Users', 'about' => 'staff accounts: add users, roles, assigned store, supervisors and PINs'],
    ['route' => 'settings.index', 'path' => 'Settings', 'about' => 'sales settings: VAT on/off, VAT rate and pricing mode, selling when out of stock, hiding out-of-stock products'],
    ['route' => 'billing.servicing.index', 'path' => 'Billing', 'about' => 'the Techiko plan: the current plan and what it uses, upgrading (pay with a QR Ph code; the plan starts once paid), renewing and past payments'],
    ['route' => 'ai-usage.index', 'path' => 'AI usage', 'about' => "this month's AI credits: the plan's allowance, credits used and left, when they reset, and usage by day, feature and user"],
];
