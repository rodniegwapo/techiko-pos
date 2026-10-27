import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { test, expect } from "./tour.js";
import { apiJson } from "../support/fixtures.js";
import { resolveAccount } from "../support/users.js";
import { pickSelectOption } from "../support/antd.js";
import { DrawerPage, CashInOutPage, numberInput, walletFixture, WALLET_DOMAIN, drawerUrl } from "../support/wallet.js";
import { SalesPage } from "../support/sales.js";

/**
 * Screens for the per-role wallet tutorials (one HyperFrames project per role). One story at
 * worker 1's E2E wallet store, role by role, saving a sharp screenshot at each step with the
 * rectangle to zoom to (CSS px of the 1568×744 page):
 *
 *   TUTORIALS_DIR="C:\...\wallet-tutorials" npx playwright test -c playwright.walkthrough.config.js capture-tutorials
 *
 * Writes <TUTORIALS_DIR>/<role>/assets/screens/<key>.jpg and shots.json for cashier, supervisor,
 * manager and owner.
 */
test.describe.configure({ mode: "serial", timeout: 180_000 });
test.use({ viewport: { width: 1568, height: 744 }, deviceScaleFactor: 2, video: "off" });

const ROOT = process.env.TUTORIALS_DIR || join(process.cwd(), "tests/e2e/walkthrough/results/tutorials");
const shots = {};
const shiftsUrl = (path = "") => `/domains/${WALLET_DOMAIN}/wallet/shifts${path}`;

/** Saves the page as <role>/assets/screens/<key>.jpg, boxing the union of `targets`. */
async function snap(page, role, key, targets, { keepNotices = false } = {}) {
    const dir = join(ROOT, role, "assets", "screens");
    mkdirSync(dir, { recursive: true });
    const list = Array.isArray(targets) ? targets : [targets];
    await list[0].scrollIntoViewIfNeeded();
    await page.waitForTimeout(700);
    if (!keepNotices) await page.evaluate(() => document.querySelectorAll(".ant-notification-notice").forEach((n) => n.remove()));
    const boxes = (await Promise.all(list.map((t) => t.boundingBox({ timeout: 5000 }).catch(() => null)))).filter(Boolean);
    const x = Math.min(...boxes.map((b) => b.x));
    const y = Math.min(...boxes.map((b) => b.y));
    const r = Math.max(...boxes.map((b) => b.x + b.width));
    const btm = Math.max(...boxes.map((b) => b.y + b.height));
    await page.screenshot({ path: join(dir, `${key}.jpg`), type: "jpeg", quality: 90 });
    (shots[role] ??= {})[key] = { box: [x, y, r - x, btm - y].map(Math.round) };
}

test.afterAll(() => {
    for (const [role, list] of Object.entries(shots)) {
        writeFileSync(join(ROOT, role, "assets", "screens", "shots.json"), JSON.stringify({ width: 1568, height: 744, shots: list }, null, 2));
    }
});

/** An HTTP session for `account` (for setting the scene, not for the screens). */
async function apiAs(playwright, baseURL, account, testInfo) {
    const api = await playwright.request.newContext({ baseURL });
    const { email, password } = resolveAccount(account, testInfo.parallelIndex);
    await api.get("/login");
    const xsrf = (await api.storageState()).cookies.find((c) => c.name === "XSRF-TOKEN");
    await api.post("/login", { form: { email, password }, headers: { "X-XSRF-TOKEN": decodeURIComponent(xsrf.value) }, maxRedirects: 0 });
    return api;
}

test.describe("Tutorial screens", () => {
    test.describe("cashier", () => {
        test.use({ account: "wallet-cashier" });

        test("cashier", async ({ page }) => {
            const R = "cashier";
            const drawer = await DrawerPage.open(page);
            const myShift = page.getByTestId("my-shift");
            await snap(page, R, "c01-drawer", [myShift.getByText("My shift", { exact: true }), page.getByTestId("open-start-my-shift")]);

            // No shift, no payment.
            await SalesPage.open(page);
            await snap(page, R, "c02-no-shift", [page.getByText(/You have no shift open/), page.getByTestId("shift-banner-action")]);

            await DrawerPage.open(page);
            await page.getByTestId("open-start-my-shift").click();
            const start = drawer.dialog("Start my shift");
            await numberInput(start, "my-shift-opening").fill("500");
            await snap(page, R, "c03-start", numberInput(start, "my-shift-opening"));
            await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
            await snap(page, R, "c04-open", [page.getByTestId("my-shift-status"), page.getByTestId("my-shift-figures").getByText("Opening")]);

            const selling = await SalesPage.open(page);
            await selling.addProduct("E2E Product A W1");
            await selling.addProduct("E2E Product A W1");
            await snap(page, R, "c05-pay", [page.getByText("Payment method", { exact: true }), page.locator(".ant-radio-button-wrapper").filter({ hasText: /^Card$/ }).first()]);
            await selling.pay({ amountReceived: 500 });

            await DrawerPage.open(page);
            await snap(page, R, "c06-expected", page.getByTestId("my-shift-figures"));

            // Ending: a cash expense, then the count.
            await page.getByTestId("open-end-my-shift").click();
            const end = drawer.dialog("End my shift");
            await end.getByTestId("end-shift-add-expense").click();
            const row = end.getByTestId("end-shift-expense").first();
            await row.locator(".ant-select").first().click();
            await page.locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option").first().click();
            await row.getByPlaceholder("What").fill("Ice for the cooler");
            await row.getByPlaceholder("₱").fill("50");
            await snap(page, R, "c07-expense", end.getByTestId("end-shift-expenses"));
            await numberInput(end, "end-shift-counted").fill("630");
            await snap(page, R, "c08-count", numberInput(end, "end-shift-counted"));
            await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
            await snap(page, R, "c09-review", [page.getByTestId("my-shift-status"), myShift.getByText(/Counted ₱/).first()]);

            await page.getByTestId("reopen-my-shift").click();
            await page.getByTestId("reopen-reason").fill("Found ₱10 in the coin tray");
            await snap(page, R, "c10-reopen", page.getByTestId("reopen-reason"));
            await drawer.save("/reopen", () => page.getByTestId("reopen-confirm").click());

            await page.getByTestId("open-end-my-shift").click();
            const again = drawer.dialog("End my shift");
            await numberInput(again, "end-shift-counted").fill("640");
            await drawer.save("/wallet/shifts/", () => again.getByTestId("confirm-end-shift").click());
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
            await snap(page, R, "c11-new-shift", [page.getByTestId("my-shift-status"), page.getByTestId("open-start-my-shift")]);
        });
    });

    test.describe("supervisor", () => {
        test.use({ account: "wallet-supervisor" });

        test("supervisor", async ({ page }) => {
            const R = "supervisor";
            const drawer = await DrawerPage.open(page);
            await page.getByTestId("open-start-my-shift").click();
            const start = drawer.dialog("Start my shift");
            await numberInput(start, "my-shift-opening").fill("300");
            await snap(page, R, "s01-own-shift", numberInput(start, "my-shift-opening"));
            await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
            await snap(page, R, "s02-store-expected", page.getByTestId("drawer-expected"));

            await page.getByTestId("open-money-by-user").click();
            await expect(page.getByTestId("money-by-user")).toBeVisible();
            await snap(page, R, "s03-money-by-user", page.getByTestId("money-by-user"));
            await page.keyboard.press("Escape");

            await page.getByTestId("wallet-tab-counts").click();
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await snap(page, R, "s04-counts", page.locator("[data-testid=counts-table] tr.ant-table-row").first());
            await page.locator("[data-testid^=count-view-]").first().click();
            await expect(page.getByTestId("count-detail")).toBeVisible();
            await snap(page, R, "s05-detail", page.getByTestId("count-detail"));
            await page.keyboard.press("Escape");

            await page.locator("[data-testid^=count-approve-]").first().click();
            await page.getByTestId("approve-note").fill("Recounted with the cashier: ₱10 short");
            await snap(page, R, "s06-approve", page.getByTestId("approve-note"));
            const approved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/approve"));
            await page.getByTestId("approve-confirm").click();
            expect((await approved).status()).toBeLessThan(400);

            // Their own shift ends ₱10 short: someone else must approve it.
            await DrawerPage.open(page);
            await page.getByTestId("open-end-my-shift").click();
            const end = drawer.dialog("End my shift");
            await numberInput(end, "end-shift-counted").fill("290");
            await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
            await page.getByTestId("wallet-tab-counts").click();
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await page.locator("[data-testid^=count-approve-]").first().click();
            await page.getByTestId("approve-confirm").click();
            const notice = page.locator(".ant-notification-notice, .ant-message-notice").first();
            await expect(notice).toBeVisible();
            await snap(page, R, "s07-not-own", notice, { keepNotices: true });
            await page.keyboard.press("Escape");
        });
    });

    test.describe("manager", () => {
        test.use({ account: "wallet-manager" });

        test("manager", async ({ page, playwright, baseURL }, testInfo) => {
            const R = "manager";
            const cash = await CashInOutPage.open(page);
            await snap(page, R, "m01-cash-page", [page.getByTestId("add-cash-in"), page.getByTestId("move-money")]);

            const cashIn = await cash.openDialog("in");
            await cash.fill(cashIn, { type: "Added float", amount: 1000, notes: "Change from the safe" });
            await snap(page, R, "m02-cash-in", [cashIn.getByTestId("cash-type"), numberInput(cashIn, "cash-amount")]);
            await cash.save(cashIn);

            const cashOut = await cash.openDialog("out");
            await cash.fill(cashOut, { type: "Owner took cash", amount: 200, notes: "Owner's allowance" });
            await snap(page, R, "m03-cash-out", [cashOut.getByTestId("cash-type"), numberInput(cashOut, "cash-amount")]);
            await cash.save(cashOut);

            const move = await cash.openDialog("move");
            await move.locator(".ant-radio-button-wrapper").filter({ hasText: "Into the channel" }).click();
            await pickSelectOption(page, move, 0, "E2E Wallet Visa");
            await numberInput(move, "cash-amount").fill("150");
            await snap(page, R, "m04-move", [move.getByTestId("move-direction"), move.getByTestId("cash-channel")]);
            await page.keyboard.press("Escape");

            // A cashier still on shift holds up the end of the day.
            const cashierApi = await apiAs(playwright, baseURL, "wallet-cashier", testInfo);
            expect((await apiJson(cashierApi, "POST", shiftsUrl("/start"), { opening_cash: 0 })).status).toBe(302);
            const drawer = await DrawerPage.open(page);
            await snap(page, R, "m05-steps", page.getByTestId("drawer-steps"));
            await snap(page, R, "m06-still-on-shift", [page.getByTestId("open-end-shift"), page.getByTestId("still-on-shift")]);
            const mine = await (await cashierApi.get(drawerUrl)).text();
            const shiftId = JSON.parse(mine.match(/data-page="([^"]*)"/)[1].replaceAll("&quot;", '"').replaceAll("&amp;", "&")).props.myShift.id;
            expect((await apiJson(cashierApi, "POST", shiftsUrl(`/${shiftId}/end`), { counted_cash: 0 })).status).toBe(302);
            await cashierApi.dispose();

            await DrawerPage.open(page);
            const expected = Number((await page.getByTestId("drawer-expected").innerText()).replace(/[^0-9.-]/g, ""));
            await page.getByTestId("open-end-shift").click();
            const end = drawer.dialog("End the store's day");
            await numberInput(end, "end-shift-counted").fill(String(expected));
            await end.getByTestId("end-shift-keep").click();
            await snap(page, R, "m07-end-day", [end.getByTestId("end-shift-cashout"), end.getByTestId("end-shift-keep")]);
            await drawer.save("/cash-ledger/close-shift", () => end.getByTestId("confirm-end-shift").click());
            await expect(page.getByTestId("shift-closed")).toBeVisible();

            await page.getByTestId("reopen-shift").click();
            await page.getByTestId("reopen-reason").fill("A late customer paid in cash");
            await snap(page, R, "m08-reopen-day", page.getByTestId("reopen-reason"));
            await drawer.save("/cash-ledger/reopen-shift", () => page.getByTestId("reopen-confirm").click());
            await expect(page.getByTestId("shift-open")).toBeVisible();
            await snap(page, R, "m09-reopened", page.getByTestId("last-reopen"));

            await page.getByTestId("wallet-tab-history").click();
            await expect(page.getByTestId("history-counts")).toBeVisible();
            await snap(page, R, "m10-history", page.getByTestId("history-counts"));

            // The supervisor's own count waits for another person: the manager.
            await page.getByTestId("wallet-tab-counts").click();
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await page.locator("[data-testid^=count-approve-]").first().click();
            await page.getByTestId("approve-note").fill("Supervisor's ₱10 short, checked");
            await snap(page, R, "m11-approve", page.getByTestId("approve-note"));
            const approved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/approve"));
            await page.getByTestId("approve-confirm").click();
            expect((await approved).status()).toBeLessThan(400);
        });
    });

    test.describe("owner", () => {
        test.use({ account: "admin" });

        test("owner", async ({ page }, testInfo) => {
            const R = "owner";
            const query = `location_id=${walletFixture(testInfo).locationId}`;

            await page.goto(`${drawerUrl}?${query}`);
            await expect(page.getByTestId("drawer-expected")).toBeVisible();
            await snap(page, R, "o01-any-store", [page.getByRole("main").getByText(walletFixture(testInfo).locationName).first(), page.getByTestId("drawer-date")]);

            await page.goto(`/domains/${WALLET_DOMAIN}/wallet/balances?${query}`);
            await expect(page.getByTestId("drawer-balance")).toBeVisible();
            await snap(page, R, "o02-balances", [page.getByTestId("drawer-balance"), page.getByTestId("channel-balances")]);

            await page.getByTestId("balances-tab-stores").click();
            await expect(page.getByTestId("all-stores-money")).toBeVisible();
            await page.waitForLoadState("networkidle");
            await snap(page, R, "o03-all-stores", page.getByTestId("all-stores-money"));

            await page.getByTestId("balances-tab-all").click();
            await expect(page.getByTestId("all-money")).toBeVisible();
            await page.waitForLoadState("networkidle");
            await snap(page, R, "o04-all-money", page.getByTestId("all-money"));

            await page.goto(`/domains/${WALLET_DOMAIN}/wallet/shifts?${query}`);
            await page.getByTestId("counts-filter-approved").click();
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await page.waitForLoadState("networkidle");
            await snap(page, R, "o05-counts", page.locator("[data-testid=counts-table] tr.ant-table-row").first());

            await page.goto(`/domains/${WALLET_DOMAIN}/wallet/history?${query}`);
            await expect(page.getByTestId("history-counts")).toBeVisible();
            await snap(page, R, "o06-history", page.getByTestId("history-shifts"));
        });
    });
});
