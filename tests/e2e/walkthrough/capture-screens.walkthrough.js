import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { test, expect } from "./tour.js";
import { DrawerPage, CashInOutPage, drawerUrl, numberInput, walletFixture, WALLET_DOMAIN } from "../support/wallet.js";
import { SalesPage } from "../support/sales.js";

/**
 * Screens for the HyperFrames explainer: the same cashier → supervisor → manager → admin story as
 * cash-wallet.walkthrough.js, but instead of a video it saves a sharp screenshot at each step, with
 * the rectangle of the thing to look at (CSS px of the 1568×744 page), so the video can zoom to it.
 *
 *   SCREENS_DIR="C:\...\assets\screens" npx playwright test -c playwright.walkthrough.config.js capture-screens
 *
 * Writes <SCREENS_DIR>/<key>.jpg and <SCREENS_DIR>/shots.json.
 */
test.describe.configure({ mode: "serial" });
test.use({ viewport: { width: 1568, height: 744 }, deviceScaleFactor: 2, video: "off" });

const DIR = process.env.SCREENS_DIR || join(process.cwd(), "tests/e2e/walkthrough/results/screens");
const shots = {};

/** Saves the page as <key>.jpg with the box of `target` (and leaves any open dialog as it is). */
async function snap(page, key, target) {
    mkdirSync(DIR, { recursive: true });
    await target.scrollIntoViewIfNeeded();
    // Let enter animations and notifications settle.
    await page.waitForTimeout(700);
    await page.evaluate(() => document.querySelectorAll(".ant-notification-notice").forEach((n) => n.remove()));
    const b = await target.boundingBox();
    await page.screenshot({ path: join(DIR, `${key}.jpg`), type: "jpeg", quality: 90 });
    shots[key] = { box: [b.x, b.y, b.width, b.height].map(Math.round) };
}

test.afterAll(() => {
    mkdirSync(DIR, { recursive: true });
    writeFileSync(join(DIR, "shots.json"), JSON.stringify({ width: 1568, height: 744, shots }, null, 2));
});

test.describe("Screens", () => {
    test.describe("cashier", () => {
        test.use({ account: "wallet-cashier" });

        test("cashier", async ({ page }) => {
            const drawer = await DrawerPage.open(page);
            await snap(page, "01-my-shift", page.getByTestId("my-shift"));

            await page.getByTestId("open-start-my-shift").click();
            const start = drawer.dialog("Start my shift");
            await numberInput(start, "my-shift-opening").fill("500");
            await snap(page, "02-start-shift", numberInput(start, "my-shift-opening"));
            await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
            await snap(page, "03-shift-open", page.getByTestId("my-shift-figures"));

            const sales = await SalesPage.open(page);
            await sales.addProduct("E2E Product A W1");
            await sales.addProduct("E2E Product A W1");
            await snap(page, "04-sale", sales.proceedButton);
            await sales.pay({ amountReceived: 500 });

            await DrawerPage.open(page);
            await snap(page, "05-expected", page.getByTestId("my-shift-figures"));

            await page.getByTestId("open-end-my-shift").click();
            const end = drawer.dialog("End my shift");
            await numberInput(end, "end-shift-counted").fill("680");
            await snap(page, "06-end-shift", numberInput(end, "end-shift-counted"));
            await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
            await snap(page, "07-needs-review", page.getByTestId("my-shift"));

            await page.getByTestId("reopen-my-shift").click();
            await page.getByTestId("reopen-reason").fill("Found ₱10 in the coin tray");
            await snap(page, "08-reopen", page.getByTestId("reopen-reason"));
            await drawer.save("/reopen", () => page.getByTestId("reopen-confirm").click());

            await page.getByTestId("open-end-my-shift").click();
            const again = drawer.dialog("End my shift");
            await numberInput(again, "end-shift-counted").fill("690");
            await drawer.save("/wallet/shifts/", () => again.getByTestId("confirm-end-shift").click());
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
        });
    });

    test.describe("supervisor", () => {
        test.use({ account: "wallet-supervisor" });

        test("supervisor", async ({ page }) => {
            const drawer = await DrawerPage.open(page);
            await page.getByTestId("open-start-my-shift").click();
            const start = drawer.dialog("Start my shift");
            await numberInput(start, "my-shift-opening").fill("300");
            await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
            await snap(page, "09-store-expected", page.getByTestId("drawer-expected"));

            await page.getByTestId("open-money-by-user").click();
            await expect(page.getByTestId("money-by-user")).toBeVisible();
            await snap(page, "10-money-by-user", page.getByTestId("money-by-user"));
            await page.keyboard.press("Escape");

            await page.getByTestId("wallet-tab-counts").click();
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await page.locator("[data-testid^=count-view-]").first().click();
            await expect(page.getByTestId("count-detail")).toBeVisible();
            await snap(page, "11-count-detail", page.getByTestId("count-detail"));
            await page.keyboard.press("Escape");

            await page.locator("[data-testid^=count-approve-]").first().click();
            await page.getByTestId("approve-note").fill("Recounted with the cashier, ₱10 short");
            await snap(page, "12-approve", page.getByTestId("approve-note"));
            const approved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/approve"));
            await page.getByTestId("approve-confirm").click();
            expect((await approved).status()).toBeLessThan(400);

            await DrawerPage.open(page);
            await page.getByTestId("open-end-my-shift").click();
            const end = drawer.dialog("End my shift");
            await numberInput(end, "end-shift-counted").fill("300");
            await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
        });
    });

    test.describe("manager", () => {
        test.use({ account: "wallet-manager" });

        test("manager", async ({ page }) => {
            const cash = await CashInOutPage.open(page);
            const dialog = await cash.openDialog("in");
            await cash.fill(dialog, { type: "Added float", amount: 1000, notes: "Change from the safe" });
            await snap(page, "13-cash-in", dialog);
            await cash.save(dialog);

            const drawer = await DrawerPage.open(page);
            await snap(page, "14-drawer-steps", page.getByTestId("drawer-steps"));
            const expected = Number((await page.getByTestId("drawer-expected").innerText()).replace(/[^0-9.-]/g, ""));

            await page.getByTestId("open-end-shift").click();
            const end = drawer.dialog("End the store's day");
            await numberInput(end, "end-shift-counted").fill(String(expected));
            await end.getByTestId("end-shift-keep").click();
            await snap(page, "15-end-day", end.getByTestId("end-shift-keep"));
            await drawer.save("/cash-ledger/close-shift", () => end.getByTestId("confirm-end-shift").click());
            await expect(page.getByTestId("shift-closed")).toBeVisible();

            await page.getByTestId("reopen-shift").click();
            await page.getByTestId("reopen-reason").fill("A late customer paid in cash");
            await snap(page, "16-reopen-day", page.getByTestId("reopen-reason"));
            await drawer.save("/cash-ledger/reopen-shift", () => page.getByTestId("reopen-confirm").click());
            await expect(page.getByTestId("shift-open")).toBeVisible();
            await snap(page, "17-day-reopened", page.getByTestId("last-reopen"));
        });
    });

    test.describe("admin", () => {
        test.use({ account: "admin" });

        test("admin", async ({ page }, testInfo) => {
            const query = `location_id=${walletFixture(testInfo).locationId}`;
            await page.goto(`/domains/${WALLET_DOMAIN}/wallet/balances?${query}`);
            await page.waitForLoadState("networkidle");
            await snap(page, "18-where-money-is", page.getByRole("main"));

            await page.goto(`/domains/${WALLET_DOMAIN}/wallet/shifts?${query}`);
            await page.getByTestId("counts-filter-approved").click();
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await page.waitForLoadState("networkidle");
            await snap(page, "19-counted-money", page.getByTestId("counts-table"));

            await page.goto(`${drawerUrl}?${query}`);
            await expect(page.getByTestId("drawer-expected")).toBeVisible();
        });
    });
});
