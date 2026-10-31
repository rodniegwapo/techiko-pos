import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { numberInput } from "../support/wallet.js";

/**
 * Cash on hand, in the retail shop of BusinessTypeSeeder (pos-retail: one store, its admin's shift
 * open each run, and a manager). The manager runs a shift and the admin approves its count; the
 * owner collects part of it into Cash on hand, then moves some back to the store. Cash on hand
 * carries over between runs, so the checks compare with what it held before.
 */
test.describe.configure({ mode: "serial" });
test.use({ account: "pos-retail" });

const domain = "pos-retail";
const url = (path) => `/domains/${domain}${path}`;

/** Cash on hand as the server has it now (the page's data-page only changes on a full load). */
async function balanceNow(page) {
    await page.reload();
    return (await pageProps(page)).balance;
}

/** The manager's shift: ₱300 opening, counted right, then approved by the admin. */
async function approvedShift(serverAs) {
    const manager = await serverAs("pos-retail-manager");
    const drawer = async () => responseProps(await manager.get(url("/wallet/drawer")));

    // A shift an earlier run left open is ended first.
    const left = (await drawer()).myShift;
    if (left?.status === "open") {
        await apiJson(manager, "POST", url(`/wallet/shifts/${left.id}/end`), { counted_cash: left.figures?.expected_cash ?? 0 });
    }
    const started = await apiJson(manager, "POST", url("/wallet/shifts/start"), { opening_cash: 300 });
    expect(started.status, JSON.stringify(started.body)).toBeLessThan(400);
    const shift = (await drawer()).myShift;
    expect(shift.status).toBe("open");

    const ended = await apiJson(manager, "POST", url(`/wallet/shifts/${shift.id}/end`), { counted_cash: 300 });
    expect(ended.status, JSON.stringify(ended.body)).toBeLessThan(400);
    const admin = await serverAs("pos-retail");
    const approved = await apiJson(admin, "POST", url(`/wallet/shifts/${shift.id}/approve`), {});
    expect(approved.status, JSON.stringify(approved.body)).toBeLessThan(400);

    return shift.id;
}

test("the owner collects an approved shift into Cash on hand and moves some back to the store", async ({ page, serverAs }) => {
    const shiftId = await approvedShift(serverAs);

    await page.goto(url("/wallet/cash-on-hand"));
    const before = (await pageProps(page)).balance;
    const waiting = page.getByTestId(`waiting-shift-${shiftId}`);
    await expect(waiting).toContainText("300");

    // Collect ₱250, leaving ₱50 change in the drawer.
    await waiting.getByTestId(`collect-shift-${shiftId}`).click();
    const modal = page.locator(".ant-modal").filter({ hasText: "Collect into Cash on hand" });
    await numberInput(modal, "collect-amount").fill("250");
    const collected = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/cash-on-hand/shifts/${shiftId}`));
    await modal.getByTestId("collect-confirm").click();
    expect((await collected).status()).toBeLessThan(400);

    await expect(waiting).toHaveCount(0);
    expect(await balanceNow(page)).toBe(before + 250);
    await expect(page.getByTestId("cash-on-hand-lines")).toContainText("Collected");

    // Shifts & counts says who collected it; collecting is on Cash on hand only.
    await page.goto(url("/wallet/shifts?status=approved"));
    await expect(page.getByTestId(`count-collected-${shiftId}`)).toContainText("Collected");
    await expect(page.getByTestId(`count-collect-${shiftId}`)).toHaveCount(0);

    // ₱100 back to the store's drawer, e.g. change for tomorrow.
    await page.goto(url("/wallet/cash-on-hand"));
    await page.getByTestId("cash-on-hand-to-store").click();
    const move = page.locator(".ant-modal").filter({ hasText: "Move to a store" });
    await numberInput(move, "cash-on-hand-amount").fill("100");
    const moved = page.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname === url("/wallet/cash-on-hand"));
    await move.getByTestId("cash-on-hand-save").click();
    expect((await moved).status()).toBeLessThan(400);
    expect(await balanceNow(page)).toBe(before + 150);
    await expect(page.getByTestId("cash-on-hand-lines")).toContainText("Moved to a store");
});

test("Where money is shows Cash on hand next to the stores", async ({ page }) => {
    await page.goto(url("/wallet/balances?tab=stores"));
    const { allStores } = await pageProps(page);
    expect(allStores.cash_on_hand).toBeGreaterThan(0);
    await expect(page.getByTestId("all-stores-cash-on-hand")).toBeVisible();
});
