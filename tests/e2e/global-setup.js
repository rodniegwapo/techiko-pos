import { execSync } from "node:child_process";

/** Creates the e2e-only accounts (idempotent). Demo users must already exist from DatabaseSeeder. */
export default function globalSetup() {
    if (process.env.E2E_SKIP_SEED) {
        return;
    }

    execSync('php artisan db:seed --class="Database\\Seeders\\E2EUserSeeder"', {
        stdio: "inherit",
    });
}
