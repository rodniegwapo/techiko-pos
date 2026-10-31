import { test, expect, apiJson } from "../support/fixtures.js";
import { login, responseProps } from "../support/inertia.js";
import { resolveAccount } from "../support/users.js";
import { drawerUrl, WALLET_DOMAIN } from "../support/wallet.js";

/**
 * Tester's bugs (Notion board):
 * - "When a cashier's shift is ended elsewhere, the Sales page doesn't show it": the page re-checks
 *   the shift on its own, so the banner appears without a reload.
 * - "Counted money: no option for reconsideration if the count was wrong": a manager sends the
 *   shift back for a recount from Counted money.
 *
 * Both use this worker's wallet cashier, so they run one after the other, and end what they open.
 */
const shiftsUrl = (path = "") => `/domains/${WALLET_DOMAIN}/wallet/shifts${path}`;
const salesUrl = `/domains/${WALLET_DOMAIN}/sales`;

async function myShift(api) {
    const res = await api.get(drawerUrl);
    expect(res.status(), "today's drawer").toBe(200);
    return (await responseProps(res)).myShift;
}

/** Ends the user's open shift, if any, with a count that matches. */
async function endOpenShift(api) {
    const mine = await myShift(api);
    if (mine?.status !== "open") return;
    const res = await apiJson(api, "POST", shiftsUrl(`/${mine.id}/end`), { counted_cash: mine.figures.expected_cash });
    expect(res.status, "end the shift left open").toBe(302);
}

async function startShift(api, opening = 0) {
    await endOpenShift(api);
    expect((await apiJson(api, "POST", shiftsUrl("/start"), { opening_cash: opening })).status, "start").toBe(302);
    return (await myShift(api)).id;
}

test.describe.configure({ mode: "serial" });

test.describe("Shift status on Sales and recounts on Counted money", () => {
    test("the Sales page shows the shift ended elsewhere without a reload", async ({ page, serverAs }, testInfo) => {
        // Polls every 30s; leave room for it.
        test.setTimeout(120_000);
        // The same cashier on "another device".
        const elsewhere = await serverAs("wallet-cashier");
        const shiftId = await startShift(elsewhere);

        try {
            await login(page, resolveAccount("wallet-cashier", testInfo.parallelIndex));
            await page.goto(salesUrl);
            const banner = page.getByTestId("shift-banner-action");
            await expect(page.getByText(/Showing \d+ of \d+/)).toBeVisible();
            await expect(banner).toHaveCount(0);

            // Lost on a full page load; Inertia's own reloads keep it.
            await page.evaluate(() => (window.__samePage = true));

            const ended = await apiJson(elsewhere, "POST", shiftsUrl(`/${shiftId}/end`), { counted_cash: 0 });
            expect(ended.status, "end the shift elsewhere").toBe(302);

            await expect(banner).toBeVisible({ timeout: 45_000 });
            await expect(banner).toHaveText(/Start my shift/);
            expect(await page.evaluate(() => window.__samePage), "the page was not reloaded").toBe(true);
        } finally {
            await endOpenShift(elsewhere);
        }
    });

    test("a manager sends a wrong count back for a recount", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        // ₱10 short: it waits on Counted money.
        const shiftId = await startShift(cashier, 100);
        expect((await apiJson(cashier, "POST", shiftsUrl(`/${shiftId}/end`), { counted_cash: 90 })).status, "end").toBe(302);

        try {
            await login(page, resolveAccount("wallet-manager", testInfo.parallelIndex));
            await page.goto(shiftsUrl());
            const recount = page.getByTestId(`count-reopen-${shiftId}`);
            await expect(recount).toBeVisible();
            await recount.click();

            const dialog = page.getByRole("dialog").filter({ hasText: "Reopen for a recount" });
            const confirm = dialog.getByTestId("reopen-confirm");
            // A reason is needed first.
            await expect(confirm).toBeDisabled();
            await dialog.getByTestId("reopen-reason").fill("Count the coins again");
            await expect(confirm).toBeEnabled();

            const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/wallet/shifts/${shiftId}/reopen`));
            await confirm.click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(recount).toHaveCount(0);

            // The cashier's shift is open again for them to count.
            const reopened = await myShift(cashier);
            expect(reopened.id).toBe(shiftId);
            expect(reopened.status).toBe("open");
        } finally {
            await endOpenShift(cashier);
        }
    });
});
