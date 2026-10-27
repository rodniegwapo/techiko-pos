import { test, expect } from "../support/fixtures.js";
import { login } from "../support/inertia.js";
import { resolveAccount } from "../support/users.js";
import { DrawerPage, numberInput, walletState, WALLET_DOMAIN } from "../support/wallet.js";

const countsUrl = `/domains/${WALLET_DOMAIN}/wallet/shifts`;

/**
 * A cashier's own shift: ended with a count and no manager's PIN; a count that is off waits on
 * Counted money, where another manager approves it. Shifts are today's, so each run first ends
 * one left open by an earlier run.
 */
test.describe("My shift and Counted money", () => {
    test.use({ account: "wallet-manager" });

    test("ending my shift with a count that is off sends it for review, and another manager approves it", async ({ page, serverAs, browser, baseURL }, testInfo) => {
        const drawer = await DrawerPage.open(page);
        const myShift = page.getByTestId("my-shift");
        await expect(myShift).toBeVisible();

        if (await page.getByTestId("open-end-my-shift").isVisible()) {
            await page.getByTestId("open-end-my-shift").click();
            await numberInput(drawer.dialog("End my shift"), "end-shift-counted").fill("0");
            await drawer.save("/wallet/shifts/", () => drawer.dialog("End my shift").getByTestId("confirm-end-shift").click());
        }

        await page.getByTestId("open-start-my-shift").click();
        const start = drawer.dialog("Start my shift");
        await numberInput(start, "my-shift-opening").fill("500");
        const started = await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
        expect(started.status()).toBeLessThan(400);
        await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
        await expect(page.getByTestId("my-shift-expected")).toContainText("500");

        // ₱20 short, and no PIN is asked for.
        await page.getByTestId("open-end-my-shift").click();
        const end = drawer.dialog("End my shift");
        await expect(end.getByTestId("end-shift-pin")).toHaveCount(0);
        await numberInput(end, "end-shift-counted").fill("480");
        const ended = await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
        expect(ended.status()).toBeLessThan(400);
        await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");

        const manager = await serverAs("wallet-manager");
        const shiftId = (await walletState(manager, new Date().toISOString().slice(0, 10))).myShift.id;

        // Another manager approves it on Counted money.
        const partnerContext = await browser.newContext({ baseURL });
        const partnerPage = await partnerContext.newPage();
        try {
            await login(partnerPage, resolveAccount("wallet-partner", testInfo.parallelIndex));
            await partnerPage.goto(countsUrl);
            const approve = partnerPage.getByTestId(`count-approve-${shiftId}`);
            await expect(approve).toBeVisible();
            await approve.click();
            await partnerPage.getByTestId("approve-note").fill("Recounted the coins");
            const saved = partnerPage.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(`/wallet/shifts/${shiftId}/approve`));
            await partnerPage.getByTestId("approve-confirm").click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(approve).toHaveCount(0);
        } finally {
            await partnerContext.close();
        }

        await DrawerPage.open(page);
        await expect(page.getByTestId("my-shift-status")).toHaveText("Approved");
    });
});
