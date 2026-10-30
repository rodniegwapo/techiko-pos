import { test, expect, apiJson } from "../support/fixtures.js";
import { login, responseProps } from "../support/inertia.js";
import { resolveAccount } from "../support/users.js";
import { balancesUrl, drawerUrl, walletFixture, WALLET_DOMAIN } from "../support/wallet.js";

/**
 * Each cashier has their own tray. Where money is shows supervisors and up a table: each place with
 * who its money came from, and the same by cashier. A cashier's Today's drawer shows cards of their
 * own money.
 */
const shiftsUrl = (path = "") => `/domains/${WALLET_DOMAIN}/wallet/shifts${path}`;

async function myShift(api) {
    const res = await api.get(drawerUrl);
    expect(res.status(), "today's drawer").toBe(200);
    return (await responseProps(res)).myShift;
}

async function endOpenShift(api) {
    const mine = await myShift(api);
    if (mine?.status !== "open") return;
    expect((await apiJson(api, "POST", shiftsUrl(`/${mine.id}/end`), { counted_cash: mine.figures.expected_cash })).status).toBe(302);
}

const number = (text) => Number(text.replace(/[^0-9.-]/g, ""));

test.describe.configure({ mode: "serial" });

test.describe("Each cashier's money", () => {
    test("a manager sees each place by cashier on Where money is, adding up to its balance", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        const { cashierName } = walletFixture(testInfo);
        await endOpenShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 125 })).status, "start").toBe(302);

        try {
            await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
            await page.goto(balancesUrl);
            await expect(page.getByTestId("money-by-place")).toBeVisible();

            // By place: open the drawer row to see the cashier's tray.
            const drawerRow = page.getByTestId("drawer-balance");
            await drawerRow.locator(".ant-table-row-expand-icon").click();
            const split = page.getByTestId("place-split-drawer");
            const mine = split.locator('[data-testid^="place-split-drawer-"]').filter({ hasText: cashierName });
            await expect(mine).toContainText("On shift");
            await expect(mine).toContainText("125.00");

            // The cashiers' trays and the store's rest add up to the drawer's balance.
            const balance = number(await page.getByTestId("drawer-balance-amount").innerText());
            const parts = await split.locator('[data-testid^="place-split-drawer-"], [data-testid="place-rest-drawer"]').allInnerTexts();
            const sum = parts.reduce((s, t) => s + number(t.split("\n").pop()), 0);
            expect(Math.round(sum * 100) / 100).toBe(balance);

            // By cashier: the same person, with their drawer.
            await page.getByTestId("money-view-cashier").click();
            const row = page.locator('[data-testid^="cashier-row-"]').filter({ hasText: cashierName });
            await expect(row.getByTestId("cell-drawer")).toContainText("125.00");
        } finally {
            await endOpenShift(cashier);
        }
    });

    test("a manager sees each cashier's shift on Today's drawer and sends a count back for a recount", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        // ₱10 short: it waits for review.
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 100 })).status, "start").toBe(302);
        const { id } = await myShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 90 })).status, "end").toBe(302);

        try {
            await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
            await page.goto(drawerUrl);
            const row = page.getByTestId(`drawer-shift-${id}`);
            await expect(row).toContainText(walletFixture(testInfo).cashierName);
            await expect(row).toContainText("Needs review");
            await expect(row).toContainText("90.00");
            // The whole store's line is the drawer's Expected.
            await expect(page.getByTestId("drawer-shift-total")).toContainText(await page.getByTestId("drawer-expected").innerText());

            await row.getByTestId(`count-reopen-${id}`).click();
            await page.getByTestId("reopen-reason").fill("Count the coins again");
            const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/wallet/shifts/${id}/reopen`));
            await page.getByTestId("reopen-confirm").click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(row).toContainText("On shift");
        } finally {
            await endOpenShift(cashier);
        }
    });

    test("an owner's Today's drawer starts with the Cash on hand they hold, then the store's drawer", async ({ page }, testInfo) => {
        await login(page, resolveAccount("admin", testInfo.parallelIndex));
        // Admins work across stores: look at this worker's wallet store.
        await page.goto(`${drawerUrl}?location_id=${walletFixture(testInfo).locationId}`);

        const card = page.getByTestId("my-cash-on-hand");
        await expect(card).toBeVisible();
        await expect(page.getByTestId("my-cash-on-hand-amount")).toContainText("₱");
        // It's every store's, and today's collection is on it.
        await expect(card).toContainText("All stores");
        await expect(page.getByTestId("my-cash-on-hand-today")).toContainText("Collected today");
        // The store's Expected is the table's whole-store line, not a card of its own.
        await expect(page.getByTestId("store-drawer-line")).toBeVisible();
        await expect(page.getByTestId("drawer-shift-total").getByTestId("drawer-expected")).toBeVisible();
    });

    test("a cashier spot checks their own drawer, against their shift's Expected", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 80 })).status, "start").toBe(302);

        try {
            await login(page, resolveAccount("wallet-cashier", testInfo.parallelIndex));
            await page.goto(drawerUrl);
            await page.getByTestId("spot-check-my-drawer").click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Spot check my drawer" });
            // Their own tray's Expected, not the store's.
            await expect(dialog.getByTestId("count-expected")).toHaveText("₱80.00");
            await dialog.getByTestId("count-amount").locator("input").or(dialog.getByTestId("count-amount")).first().fill("80");
            const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/spot-check"));
            await dialog.getByRole("button", { name: "Save spot check" }).click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(page.getByTestId("my-last-spot-check")).toContainText("₱80.00");
            // Still on shift.
            await expect(page.getByTestId("open-end-my-shift")).toBeVisible();
        } finally {
            await endOpenShift(cashier);
        }
    });

    test("a manager spot checks only their own drawer, never a cashier's or the whole store", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        const manager = await serverAs("wallet-manager");
        await endOpenShift(cashier);
        await endOpenShift(manager);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 45 })).status, "cashier starts").toBe(302);
        expect((await apiJson(manager, "POST", shiftsUrl("/start"), { opening_cash: 30 })).status, "manager starts").toBe(302);
        const cashierShift = await myShift(cashier);

        try {
            await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
            await page.goto(drawerUrl);
            // No spot check on the cashier's row, and no count of the whole store.
            await expect(page.getByTestId(`drawer-shift-${cashierShift.id}`)).toBeVisible();
            await expect(page.locator('[data-testid^="spot-check-shift-"]')).toHaveCount(0);
            await expect(page.getByTestId("open-count-cash")).toHaveCount(0);
            expect((await apiJson(manager, "POST", shiftsUrl(`/${cashierShift.id}/spot-check`), { counted_cash: 45 })).status).toBe(422);

            // Their own drawer, against their own shift's Expected.
            await page.getByTestId("spot-check-my-drawer").click();
            const dialog = page.getByRole("dialog").filter({ hasText: "Spot check my drawer" });
            await expect(dialog.getByTestId("count-expected")).toHaveText("₱30.00");
            await dialog.getByTestId("count-amount").locator("input").or(dialog.getByTestId("count-amount")).first().fill("25");
            await expect(dialog.getByTestId("count-preview")).toContainText("Short");
            const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/spot-check"));
            await dialog.getByRole("button", { name: "Save spot check" }).click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(page.getByTestId("my-last-spot-check")).toContainText("₱25.00");
        } finally {
            await endOpenShift(cashier);
            await endOpenShift(manager);
        }
    });

    test("Today's drawer shows a cashier ending their shift elsewhere without a reload", async ({ page, serverAs }, testInfo) => {
        // Re-checks every 30s; leave room for it.
        test.setTimeout(120_000);
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 35 })).status, "start").toBe(302);
        const { id } = await myShift(cashier);

        try {
            await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
            await page.goto(drawerUrl);
            const row = page.getByTestId(`drawer-shift-${id}`);
            await expect(row).toContainText("On shift");
            // Lost on a full page load; the page's own refresh keeps it.
            await page.evaluate(() => (window.__samePage = true));

            // The cashier ends it on their own device.
            expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 35 })).status, "end").toBe(302);

            await expect(row).toContainText("Ended", { timeout: 45_000 });
            expect(await page.evaluate(() => window.__samePage), "the page was not reloaded").toBe(true);
        } finally {
            await endOpenShift(cashier);
        }
    });

    test("a manager reads the day at a glance and approves from Needs your review", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        // ₱10 short: it waits for review.
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 100 })).status, "start").toBe(302);
        const { id } = await myShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 90 })).status, "end").toBe(302);

        await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
        await page.goto(drawerUrl);
        await expect(page.getByTestId("drawer-summary")).toBeVisible();
        await expect(page.getByTestId("summary-discrepancy")).toContainText("waiting for review");

        // The filter narrows the shifts to the ones waiting.
        await page.getByTestId("drawer-shift-filter-needs_review").click();
        await expect(page.getByTestId(`drawer-shift-${id}`)).toBeVisible();
        // The whole store's line always stays.
        await expect(page.getByTestId("drawer-shift-total")).toBeVisible();

        // The shift opens in full in the side panel.
        await page.getByTestId(`drawer-shift-view-${id}`).click();
        await expect(page.getByTestId("shift-sheet-expected")).toHaveText("₱100.00");
        await expect(page.getByTestId("shift-timeline")).toContainText("Shift started");
        await page.keyboard.press("Escape");

        // Approved right from Needs your review.
        const card = page.getByTestId(`required-action-${id}`);
        await expect(card).toContainText(walletFixture(testInfo).cashierName);
        await card.getByTestId(`count-approve-${id}`).click();
        const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/wallet/shifts/${id}/approve`));
        await page.getByTestId("approve-confirm").click();
        expect((await saved).status()).toBeLessThan(400);
        await expect(page.getByTestId(`required-action-${id}`)).toHaveCount(0);
    });

    test("Where money is groups the places with a subtotal each, under the totals", async ({ page }, testInfo) => {
        await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
        await page.goto("/domains/jollibee-corp/wallet/balances");
        await expect(page.getByTestId("balance-totals")).toBeVisible();
        await expect(page.getByTestId("place-group-cash")).toContainText("Subtotal");
        // The worker's store has card terminals: they're under Banks & card terminals.
        await expect(page.getByTestId("place-group-bank")).toContainText("E2E Wallet Visa");
    });

    test("a cashier sees cards of their own money on Today's drawer", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 60 })).status, "start").toBe(302);

        try {
            await login(page, resolveAccount("wallet-cashier", testInfo.parallelIndex));
            await page.goto(drawerUrl);
            const cards = page.getByTestId("my-takings");
            await expect(cards).toBeVisible();
            await expect(cards.getByTestId("my-takings-drawer")).toContainText("60.00");
            // Not the store's whole drawer.
            await expect(page.getByTestId("drawer-expected")).toHaveCount(0);
        } finally {
            await endOpenShift(cashier);
        }
    });
});
