import { responseProps } from "./inertia.js";

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

/** Admins of the one-organization-per-plan fixtures (database/seeders/E2EPlanSeeder.php). */
for (const plan of ["free", "basic", "standard", "premium"]) {
    const domain = `e2e-plan-${plan}`;
    USERS[`plan-${plan}`] = { email: `${domain}-admin@techiko.test`, password: "e2e-password", domain, role: "admin" };
}

/** Admins of the one-organization-per-business-type fixtures (database/seeders/BusinessTypeSeeder.php). */
export const BUSINESS_TYPES = ["retail", "food", "grocery", "services", "other"];
for (const type of BUSINESS_TYPES) {
    const domain = `pos-${type}`;
    USERS[domain] = { email: `${domain}-admin@techiko.test`, password: "e2e-password", domain, role: "admin" };
}

export const OTHER_DOMAIN = "mcdonalds-corp";

/**
 * A store of the organization, for new staff: managers and the roles below them must be given
 * where they work when an organization has more than one location. Read from the Users page.
 */
export async function storeLocationId(api, domain = "jollibee-corp") {
    const props = await responseProps(await api.get(`/domains/${domain}/users`));
    return (props.locations ?? []).find((l) => l.type !== "warehouse")?.id ?? null;
}

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

/** Role of each wallet account kind (E2EWalletSeeder). */
const WALLET_ROLES = { manager: "manager", partner: "manager", cashier: "cashier", supervisor: "supervisor" };

/**
 * Wallet accounts from E2EWalletSeeder: each worker has its own store with a manager, a second
 * manager ("partner"), a cashier and a supervisor, so wallet tests never share a store's cash data.
 */
export function walletUser(kind, parallelIndex) {
    const n = workerNumber(parallelIndex);
    return {
        email: `e2e-wallet-${kind}-${n}@techiko.test`,
        password: "e2e-password",
        domain: "jollibee-corp",
        role: WALLET_ROLES[kind],
    };
}

/** Resolves an `account` option: a USERS key, "worker-cashier", or "wallet-manager|partner|cashier|supervisor". */
export function resolveAccount(account, parallelIndex) {
    if (account === "worker-cashier") {
        return workerCashier(parallelIndex);
    }
    const walletKind = account.startsWith("wallet-") ? account.slice("wallet-".length) : null;
    if (walletKind && WALLET_ROLES[walletKind]) {
        return walletUser(walletKind, parallelIndex);
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
        // Only modifiers.spec.js uses this: it asks for its "E2E Size" (Regular, or Large +₱20) before adding.
        latte: { name: "E2E Latte", price: 100, stock: 1000 },
        // Only weighed.spec.js uses this: ₱50 per kg, it asks "How much?" before adding.
        rice: { name: "E2E Rice", price: 50, stock: 100 },
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
