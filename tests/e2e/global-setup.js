import { execSync } from "node:child_process";

/**
 * Creates the e2e-only accounts and resets the Sales fixtures (stock, points, credit,
 * pending carts). Idempotent. Demo users must already exist from DatabaseSeeder.
 */
export default function globalSetup() {
    if (process.env.E2E_SKIP_SEED) {
        return;
    }

    for (const seeder of ["E2EUserSeeder", "E2ESalesSeeder"]) {
        execSync(`php artisan db:seed --class="Database\\Seeders\\${seeder}"`, { stdio: "inherit" });
    }
}
