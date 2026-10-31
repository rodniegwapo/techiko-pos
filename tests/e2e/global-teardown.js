import { execSync } from "node:child_process";

/**
 * Undoes what the run changed outside its own fixtures: puts back Jollibee's Finance / AI support
 * switches, which global-setup turned on for the finance specs, and ends the shifts the setup
 * opened for the accounts the suite sells as (the demo admin, manager and cashier among them).
 */
export default function globalTeardown() {
    if (process.env.E2E_SKIP_SEED) {
        return;
    }

    execSync(`php artisan db:seed --class="Database\\Seeders\\E2ERestoreDomainFeaturesSeeder"`, { stdio: "inherit" });
    execSync(`php artisan db:seed --class="Database\\Seeders\\E2EEndSetupShiftsSeeder"`, { stdio: "inherit" });
}
