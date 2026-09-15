/**
 * Accounts the e2e suite signs in as.
 * - admin/manager/cashier come from database/seeders/UserSeeder.php
 * - super/noDashboard come from database/seeders/E2EUserSeeder.php (run in global-setup)
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
};

export const OTHER_DOMAIN = "mcdonalds-corp";
