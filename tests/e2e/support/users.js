/**
 * Accounts the e2e suite signs in as.
 * - admin/manager/cashier (Jollibee) and mcCashier come from database/seeders/UserSeeder.php
 * - super/noDashboard come from database/seeders/E2EUserSeeder.php
 * - e2e-cashier-1..4 come from database/seeders/E2ESalesSeeder.php (see workerCashier)
 */
export const USERS = {
    super: {
        email: "e2e-super@techiko.test",
        password: "e2e-password",
        domain: null,
    },
    admin: {
        email: "admin@jollibee-corp.com",
        password: "jollibee123",
        domain: "jollibee-corp",
        role: "admin",
    },
    manager: {
        email: "manager@jollibee-corp.com",
        password: "jollibee123",
        domain: "jollibee-corp",
        role: "manager",
        locationCode: "JB-MAIN",
    },
    cashier: {
        email: "cashier1@jollibee-corp.com",
        password: "jollibee123",
        domain: "jollibee-corp",
        role: "cashier",
        locationCode: "JB-MAIN",
    },
    noDashboard: {
        email: "e2e-no-dashboard@techiko.test",
        password: "e2e-password",
        domain: "jollibee-corp",
    },
    mcCashier: {
        email: "cashier1@mcdonalds-corp.com",
        password: "mcdonalds123",
        domain: "mcdonalds-corp",
        role: "cashier",
    },
    mcManager: {
        email: "manager@mcdonalds-corp.com",
        password: "mcdonalds123",
        domain: "mcdonalds-corp",
        role: "manager",
    },
};

export const OTHER_DOMAIN = "mcdonalds-corp";

/** Seeded worker cashiers (E2ESalesSeeder::WORKER_CASHIERS). */
export const WORKER_CASHIER_COUNT = 4;

/**
 * Each Playwright worker signs in as its own cashier, because a user has one pending cart
 * and parallel tests sharing a cashier would edit the same cart.
 */
export function workerCashier(parallelIndex) {
    const n = (parallelIndex % WORKER_CASHIER_COUNT) + 1;
    return {
        email: `e2e-cashier-${n}@techiko.test`,
        password: "e2e-password",
        domain: "jollibee-corp",
        role: "cashier",
        locationCode: "JB-MAIN",
    };
}

/** Worker number (1..4) shared by the sales cashiers and wallet stores. */
export const workerNumber = (parallelIndex) => (parallelIndex % WORKER_CASHIER_COUNT) + 1;

/**
 * Wallet accounts from E2EWalletSeeder: each worker has its own store with a manager and a
 * second manager ("partner"), so wallet tests never share a store's cash data.
 */
export function walletUser(kind, parallelIndex) {
    const n = workerNumber(parallelIndex);
    return {
        email: `e2e-wallet-${kind}-${n}@techiko.test`,
        password: "e2e-password",
        domain: "jollibee-corp",
        role: "manager",
    };
}

/** Resolves an `account` option: a USERS key, "worker-cashier", "wallet-manager" or "wallet-partner". */
export function resolveAccount(account, parallelIndex) {
    if (account === "worker-cashier") {
        return workerCashier(parallelIndex);
    }
    if (account === "wallet-manager" || account === "wallet-partner") {
        return walletUser(account.replace("wallet-", ""), parallelIndex);
    }
    const user = USERS[account];
    if (!user) {
        throw new Error(`Unknown e2e account "${account}"`);
    }
    return user;
}

/** Records created by E2ESalesSeeder. */
export const E2E = {
    domain: "jollibee-corp",
    products: {
        burger: { name: "E2E Burger", price: 100, stock: 1000 },
        fries: { name: "E2E Fries", price: 50, stock: 1000 },
        limited: { name: "E2E Limited", price: 80, stock: 2 },
        soldOut: { name: "E2E Sold Out", price: 60, stock: 0 },
        // Only security.spec.js touches this one, so its cross-cart probes can't disturb other tests.
        probe: { name: "E2E Security Probe", price: 10, stock: 1000 },
    },
    customers: {
        loyalty: { name: "E2E Loyalty Customer", points: 5000 },
        credit: { name: "E2E Credit Customer", creditLimit: 500 },
        noCredit: { name: "E2E No Credit Customer" },
    },
    cards: { visa: "E2E Visa", inactive: "E2E Inactive Card", otherOrg: "E2E McDonalds Card" },
    discounts: { order: "E2E 10% Order", item: "E2E ₱20 Item", senior: "E2E Senior 20%", pwd: "E2E PWD 20%" },
    pins: { manager: "1234", admin: "4567" },
};
