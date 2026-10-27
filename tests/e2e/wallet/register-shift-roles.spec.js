import { test, expect, apiJson } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";
import { DrawerPage, drawerUrl, numberInput, walletFixture, WALLET_DOMAIN } from "../support/wallet.js";

/**
 * Cashier shifts and Counted money, role by role, at this worker's own wallet store
 * (E2EWalletSeeder): cashier, supervisor, manager, admin and super user.
 *
 * - Everyone on the till starts and ends their own shift; no manager's PIN.
 * - A count that is off is "Needs review"; supervisors and up approve it on Counted money and see
 *   who holds what (View details). Cashiers see neither.
 * - Only managers and up end the store's day, and only once nobody is still on shift.
 *
 * Shifts are today's and these stores are shared with the other wallet specs of this worker, so
 * every test ends the shifts it opens: an open shift would stop "End the day" there.
 */
const shiftsUrl = (path = "") => `/domains/${WALLET_DOMAIN}/wallet/shifts${path}`;

async function drawerProps(api, query = {}) {
    const params = new URLSearchParams(query);
    const res = await api.get(`${drawerUrl}${params.size ? `?${params}` : ""}`);
    expect(res.status(), "today's drawer").toBe(200);
    return responseProps(res);
}

/** Ends the user's open shift, if any, with a count that matches. */
async function endOpenShift(api) {
    const mine = (await drawerProps(api)).myShift;
    if (mine?.status !== "open") return;
    const res = await apiJson(api, "POST", shiftsUrl(`/${mine.id}/end`), { counted_cash: mine.figures.expected_cash });
    expect(res.status, "end the shift left open").toBe(302);
}

/** A shift of this user that ended ₱10 short: it waits for review. Returns its id. */
async function shiftNeedingReview(api) {
    await endOpenShift(api);
    expect((await apiJson(api, "POST", shiftsUrl("/start"), { opening_cash: 100 })).status, "start").toBe(302);
    const { id } = (await drawerProps(api)).myShift;
    expect((await apiJson(api, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 90 })).status, "end").toBe(302);
    return id;
}

async function openStartAndEnd(page, drawer, { opening, counted }) {
    await page.getByTestId("open-start-my-shift").click();
    const start = drawer.dialog("Start my shift");
    await numberInput(start, "my-shift-opening").fill(String(opening));
    expect((await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click())).status()).toBeLessThan(400);
    await expect(page.getByTestId("my-shift-status")).toHaveText("Open");

    await page.getByTestId("open-end-my-shift").click();
    const end = drawer.dialog("End my shift");
    // No manager's PIN anywhere.
    await expect(end.getByTestId("end-shift-pin")).toHaveCount(0);
    await expect(end.getByPlaceholder("A manager approves the cash")).toHaveCount(0);
    await numberInput(end, "end-shift-counted").fill(String(counted));
    expect((await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click())).status()).toBeLessThan(400);
}

test.describe("Cashier shifts as a cashier", () => {
    test.use({ account: "wallet-cashier" });

    test.afterEach(async ({ serverAs }) => endOpenShift(await serverAs("wallet-cashier")));

    test("ends their shift with no PIN; a short count goes to review and they can start again", async ({ page, serverAs }) => {
        await endOpenShift(await serverAs("wallet-cashier"));
        const drawer = await DrawerPage.open(page);

        await openStartAndEnd(page, drawer, { opening: 200, counted: 150 });

        await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
        await expect(page.getByTestId("my-shift")).toContainText("a manager will review it");
        // A cashier sees what should be there, not whether they came out short.
        await expect(page.getByTestId("my-shift")).not.toContainText("Short");

        // Not stopped: a new shift starts straight away.
        await page.getByTestId("open-start-my-shift").click();
        const again = drawer.dialog("Start my shift");
        expect((await drawer.save("/wallet/shifts/start", () => again.getByTestId("confirm-start-my-shift").click())).status()).toBeLessThan(400);
        await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
    });

    test("sees no Counted money, View details or End the day", async ({ page }) => {
        await DrawerPage.open(page);

        await expect(page.getByTestId("my-shift")).toBeVisible();
        await expect(page.getByTestId("open-end-shift")).toHaveCount(0);
        await expect(page.getByTestId("open-money-by-user")).toHaveCount(0);
        await expect(page.getByTestId("wallet-tab-counts")).toHaveCount(0);
    });

    test("is refused Counted money, money per user and approving", async ({ serverAs }) => {
        const api = await serverAs("wallet-cashier");
        const id = await shiftNeedingReview(api);

        expect((await apiJson(api, "GET", shiftsUrl())).status).toBe(403);
        expect((await apiJson(api, "GET", shiftsUrl("/money-by-user"))).status).toBe(403);
        expect((await apiJson(api, "POST", shiftsUrl(`/${id}/approve`), {})).status).toBe(403);
        // Nor can they end the store's day.
        const close = await apiJson(api, "POST", "/domains/jollibee-corp/wallet/cash-ledger/close-shift", {
            business_date: new Date().toISOString().slice(0, 10), counted_cash: 0, end_shift_action: "save_as_opening_cash",
        });
        expect(close.status).toBe(422);
    });

    test("reopens their own shift only with a reason, and the first count stays", async ({ page, serverAs }) => {
        const api = await serverAs("wallet-cashier");
        await shiftNeedingReview(api);
        const drawer = await DrawerPage.open(page);

        await page.getByTestId("reopen-my-shift").click();
        await expect(page.getByTestId("reopen-confirm")).toBeDisabled();
        expect((await apiJson(api, "POST", shiftsUrl(`/${(await drawerProps(api)).myShift.id}/reopen`), {})).status).toBe(422);

        await page.getByTestId("reopen-reason").fill("Found ₱10 under the tray");
        expect((await drawer.save("/reopen", () => page.getByTestId("reopen-confirm").click())).status()).toBeLessThan(400);
        await expect(page.getByTestId("my-shift-status")).toHaveText("Open");

        const supervisor = await serverAs("wallet-supervisor");
        const shiftId = (await drawerProps(api)).myShift.id;
        const detail = await apiJson(supervisor, "GET", shiftsUrl(`/${shiftId}`));
        expect(detail.body.counts).toHaveLength(1);
        expect(detail.body.reopens[0].reason).toBe("Found ₱10 under the tray");
        expect(detail.body.reopens[0].before.status).toBe("needs_review");
    });
});

test.describe("Cashier shifts as a supervisor", () => {
    test.use({ account: "wallet-supervisor" });

    test.afterEach(async ({ serverAs }) => {
        await endOpenShift(await serverAs("wallet-cashier"));
        await endOpenShift(await serverAs("wallet-supervisor"));
    });

    test("approves a cashier's count on Counted money", async ({ page, serverAs }) => {
        const id = await shiftNeedingReview(await serverAs("wallet-cashier"));

        await DrawerPage.open(page);
        await page.getByTestId("wallet-tab-counts").click();
        await expect(page.getByTestId("counts-table")).toBeVisible();

        await page.getByTestId(`count-view-${id}`).click();
        await expect(page.getByTestId("count-detail")).toContainText("Counts");
        await page.keyboard.press("Escape");

        await page.getByTestId(`count-approve-${id}`).click();
        await page.getByTestId("approve-note").fill("Recounted with the cashier");
        const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/wallet/shifts/${id}/approve`));
        await page.getByTestId("approve-confirm").click();
        expect((await saved).status()).toBeLessThan(400);
        await expect(page.getByTestId(`count-approve-${id}`)).toHaveCount(0);

        await page.getByTestId("counts-filter-approved").click();
        await expect(page.getByTestId(`count-view-${id}`)).toBeVisible();
    });

    test("sees each person's money with View details, but doesn't end the store's day", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 75 })).status).toBe(302);

        await DrawerPage.open(page);
        await expect(page.getByTestId("open-end-shift")).toHaveCount(0);
        await page.getByTestId("open-money-by-user").click();
        const list = page.getByTestId("money-by-user");
        await expect(list).toContainText(walletFixture(testInfo).cashierName);
        await expect(list).toContainText("In their drawer now");
    });

    test("runs their own shift, but another person approves it", async ({ serverAs }) => {
        const api = await serverAs("wallet-supervisor");
        const id = await shiftNeedingReview(api);

        const own = await apiJson(api, "POST", shiftsUrl(`/${id}/approve`), {});
        expect(own.status).toBe(422);
        expect(Object.keys(own.body.errors)).toContain("shift");

        expect((await apiJson(await serverAs("wallet-manager"), "POST", shiftsUrl(`/${id}/approve`), {})).status).toBe(302);
    });
});

test.describe("Cashier shifts as a manager", () => {
    test.use({ account: "wallet-manager" });

    test.afterEach(async ({ serverAs }) => endOpenShift(await serverAs("wallet-cashier")));

    test("End the day waits until every cashier has ended their shift", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 0 })).status).toBe(302);

        await DrawerPage.open(page);
        await expect(page.getByTestId("open-end-shift")).toBeDisabled();
        await expect(page.getByTestId("still-on-shift")).toContainText(walletFixture(testInfo).cashierName);

        await endOpenShift(cashier);
        await DrawerPage.open(page);
        await expect(page.getByTestId("open-end-shift")).toBeEnabled();
        await expect(page.getByTestId("still-on-shift")).toHaveCount(0);
    });

    test("sees Counted money and View details", async ({ page }) => {
        await DrawerPage.open(page);
        await expect(page.getByTestId("open-money-by-user")).toBeVisible();
        await page.getByTestId("wallet-tab-counts").click();
        await expect(page.getByTestId("counts-filter")).toBeVisible();
    });
});

for (const account of ["admin", "super"]) {
    test.describe(`Cashier shifts as ${account === "super" ? "a super user" : "an admin"}`, () => {
        test.use({ account });

        test("approves a cashier's count at any store", async ({ page, serverAs }, testInfo) => {
            const id = await shiftNeedingReview(await serverAs("wallet-cashier"));
            const store = walletFixture(testInfo).locationId;

            await page.goto(`${shiftsUrl()}?location_id=${store}`);
            await page.getByTestId(`count-approve-${id}`).click();
            const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/wallet/shifts/${id}/approve`));
            await page.getByTestId("approve-confirm").click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(page.getByTestId(`count-approve-${id}`)).toHaveCount(0);

            await page.goto(`${drawerUrl}?location_id=${store}`);
            await page.getByTestId("open-money-by-user").click();
            await expect(page.getByTestId("money-by-user")).toContainText(walletFixture(testInfo).cashierName);
        });
    });
}
