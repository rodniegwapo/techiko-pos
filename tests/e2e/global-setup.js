import { execSync } from "node:child_process";
import { rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

/**
 * Creates the e2e-only accounts and resets the Sales and Wallet fixtures (stock, points, credit,
 * pending carts, wallet stores). Idempotent. Demo users must already exist from DatabaseSeeder.
 */
export default function globalSetup() {
    if (process.env.E2E_SKIP_SEED) {
        return;
    }

    // Order matters: E2ESalesSeeder writes tests/e2e/.fixtures.json, the later ones add to it,
    // and E2EProductSeeder stocks the stores E2EWalletSeeder creates.
    for (const seeder of ["E2EUserSeeder", "E2ESalesSeeder", "E2EWalletSeeder", "E2EProductSeeder"]) {
        execSync(`php artisan db:seed --class="Database\\Seeders\\${seeder}"`, { stdio: "inherit" });
    }

    // Wallet stores were just wiped, so business-date allocation can start over (support/wallet.js).
    for (let n = 1; n <= 4; n++) {
        rmSync(join(tmpdir(), `techiko-e2e-wallet-dates-${n}.txt`), { force: true });
    }
}
