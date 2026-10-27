import { defineConfig, devices } from "@playwright/test";
import base from "./playwright.config.js";

/**
 * Walkthrough videos (tests/e2e/walkthrough): one recorded chapter per role, run one after the
 * other on worker 1's E2E wallet store, slowed down and captioned for explaining the app.
 *
 *   npx playwright test -c playwright.walkthrough.config.js
 *
 * The finished videos are copied to tests/e2e/walkthrough/videos/ (01-cashier.webm, ...).
 */
export default defineConfig({
    ...base,
    testDir: "tests/e2e/walkthrough",
    testMatch: "**/*.walkthrough.js",
    outputDir: "tests/e2e/walkthrough/results",
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 10 * 60_000,
    reporter: [["list"]],
    use: {
        ...base.use,
        headless: false,
        viewport: { width: 1280, height: 720 },
        video: { mode: "on", size: { width: 1280, height: 720 } },
        launchOptions: { slowMo: Number(process.env.E2E_SLOWMO) || 250 },
    },
    projects: [{ name: "walkthrough", use: { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 720 } } }],
});
