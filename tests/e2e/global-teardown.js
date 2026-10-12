import { execSync } from "node:child_process";

/**
 * Undoes what the run changed outside its own fixtures: puts back Jollibee's Finance / AI support
 * switches, which global-setup turned on for the finance specs.
 */
export default function globalTeardown() {
    if (process.env.E2E_SKIP_SEED) {
        return;
    }

    execSync(`php artisan db:seed --class="Database\\Seeders\\E2ERestoreDomainFeaturesSeeder"`, { stdio: "inherit" });
}
