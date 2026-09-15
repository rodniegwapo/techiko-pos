import { defineConfig, devices } from "@playwright/test";
import { existsSync } from "node:fs";

// Local overrides (base URL, test credentials). See .env.playwright.example.
if (existsSync(".env.playwright")) {
    process.loadEnvFile(".env.playwright");
}

/**
 * The app is served by Laragon (http://techiko-pos.test), so Playwright does
 * not start a web server. Keep `npm run dev` (or a build) running for assets.
 */
export default defineConfig({
    testDir: "tests/e2e",
    globalSetup: "./tests/e2e/global-setup.js",
    outputDir: "tests/e2e/test-results",
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // At most one worker per seeded sales cashier (E2ESalesSeeder::WORKER_CASHIERS): each has its own cart.
    workers: process.env.CI ? 1 : 4,
    // Checkout flows make several slow round trips on a local server.
    timeout: 60_000,
    reporter: [
        ["list"],
        ["html", { outputFolder: "tests/e2e/playwright-report", open: "never" }],
        // Plain-language summary of failures and unusual behavior to read after a run.
        ["./tests/e2e/support/findings-reporter.js", { outputFile: "tests/e2e/findings-report.md" }],
    ],
    use: {
        baseURL: process.env.E2E_BASE_URL || "http://techiko-pos.test",
        trace: "on-first-retry",
        screenshot: "only-on-failure",
        video: "retain-on-failure",
        // E2E_SLOWMO=500 slows each browser action so a headed run is easy to follow.
        launchOptions: { slowMo: Number(process.env.E2E_SLOWMO) || 0 },
    },
    projects: [
        {
            name: "chromium",
            // Signed out by default; specs pick an account with test.use({ account: "cashier" }) (support/fixtures.js).
            use: { ...devices["Desktop Chrome"] },
        },
    ],
});
