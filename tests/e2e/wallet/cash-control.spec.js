import { test, expect } from "../support/fixtures.js";
import {
    CashInOutPage,
    DrawerPage,
    dateWindow,
    expectSaved,
    fillDatePicker,
    historyUrl,
    money,
    numberInput,
    postCounted,
    postEndShift,
    postLedger,
    postOpening,
    postReopen,
    walletFixture,
    walletState,
} from "../support/wallet.js";

test.describe("Today's drawer", () => {
    test.use({ account: "wallet-manager" });

    test("a fresh day expects nothing and isn't counted yet", async ({ page }) => {
        const { day } = dateWindow();
        const drawer = await DrawerPage.open(page, day(0));

        await expect(drawer.expected).toHaveText(money(0));
        await expect(drawer.counted).toHaveText("—");
        await expect(drawer.result).toHaveText("Not counted");
    });

    test("picking a date opens that day straight away", async ({ page }) => {
        const { day } = dateWindow();
        const drawer = await DrawerPage.open(page, day(0));

        const reloaded = page.waitForURL(new RegExp(`business_date=${day(1)}`));
        await fillDatePicker(page.getByRole("main"), day(1));
        await reloaded;

        await expect(page.getByText("Back to today")).toBeVisible();
        await expect(drawer.expected).toBeVisible();
    });

    test("the steps add up to Expected", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const api = await serverAs("wallet-manager");
        expectSaved(await postOpening(api, { business_date: day(0), opening_cash: 1000 }), "opening");
        expectSaved(await postLedger(api, { direction: "in", amount: 250, kind: "cash_sale_topup", movement_date: day(0) }), "cash in");
        expectSaved(
            await postLedger(api, { direction: "out", amount: 100, kind: "owner_draw", draw_source: "cash_register", movement_date: day(0) }),
            "cash out",
        );

        const drawer = await DrawerPage.open(page, day(0));

        await expect(drawer.step("opening")).toContainText(money(1000));
        await expect(drawer.step("sales")).toContainText(money(0));
        await expect(drawer.step("in")).toContainText(money(250));
        await expect(drawer.step("out")).toContainText(money(100));
        await expect(drawer.expected).toHaveText(money(1150));
    });

    test.describe("opening cash", () => {
        test("Save opening is disabled for zero", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            const dialog = await drawer.openSetOpening();

            await numberInput(dialog, "opening-amount").fill("0");
            await expect(dialog.getByRole("button", { name: "Save opening" })).toBeDisabled();

            await numberInput(dialog, "opening-amount").fill("250");
            await expect(dialog.getByRole("button", { name: "Save opening" })).toBeEnabled();
        });

        test("saving opening cash updates Expected and is kept in the history", async ({ page }, testInfo) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));

            const res = await drawer.setOpening(500, "Float from safe");

            expect(res.status()).toBeLessThan(400);
            await expect(drawer.notice("Opening cash saved.")).toBeVisible();
            await expect(drawer.expected).toHaveText(money(500));

            await page.goto(`${historyUrl}?business_date=${day(0)}`);
            const openings = page.getByTestId("history-openings");
            await expect(openings).toContainText("Float from safe");
            await expect(openings).toContainText(walletFixture(testInfo).managerName);
        });

        test("changing opening cash keeps an audit trail", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));

            await drawer.setOpening(300);
            await drawer.setOpening(350, "Counted again");

            await expect(drawer.expected).toHaveText(money(350));
            await page.goto(`${historyUrl}?business_date=${day(0)}`);
            await expect(page.getByTestId("history-openings")).toContainText(`${money(300)} → ${money(350)}`);
        });

        test("suggests opening cash from the previous day's count", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 420 }), "count day 0");

            const drawer = await DrawerPage.open(page, day(1));
            await expect(page.getByTestId("drawer-steps")).toContainText(`Opening suggested from the count on ${day(0)}`);

            const dialog = await drawer.openSetOpening();
            await expect(dialog).toContainText(`what was counted on ${day(0)}`);
            await expect(numberInput(dialog, "opening-amount")).toHaveValue("420.00");
        });
    });

    test.describe("counting cash", () => {
        test("Save count is disabled until an amount is entered, and previews short or over", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(500);
            const dialog = await drawer.openCountCash();

            await expect(dialog.getByRole("button", { name: "Save count" })).toBeDisabled();
            await numberInput(dialog, "count-amount").fill("450");
            await expect(dialog.getByTestId("count-preview")).toHaveText(`Short ${money(50)}`);
            await expect(dialog.getByRole("button", { name: "Save count" })).toBeEnabled();
        });

        test("an empty drawer (zero) can be counted", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));

            const res = await drawer.countCash(0);

            expect(res.status()).toBeLessThan(400);
            await expect(drawer.counted).toHaveText(money(0));
            await expect(drawer.result).toHaveText("Balanced");
        });

        test("a short count shows Short and a count difference line", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(500);

            const res = await drawer.countCash(450, "Short by 50");

            expect(res.status()).toBeLessThan(400);
            await expect(drawer.notice("Count saved.")).toBeVisible();
            await expect(drawer.counted).toHaveText(money(450));
            await expect(drawer.result).toHaveText(`Short ${money(50)}`);

            const cash = await CashInOutPage.open(page, { date_from: day(0), date_to: day(0) });
            await expect(cash.row("Count difference")).toHaveCount(1);
            await expect(cash.row("Count difference")).toContainText(`− ${money(50)}`);
        });

        test("recounting replaces the count difference instead of adding another", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(500);
            await drawer.countCash(450);

            await drawer.countCash(480);

            await expect(drawer.result).toHaveText(`Short ${money(20)}`);
            const cash = await CashInOutPage.open(page, { date_from: day(0), date_to: day(0) });
            await expect(cash.row("Count difference")).toHaveCount(1);
            await expect(cash.row("Count difference")).toContainText(`− ${money(20)}`);
        });

        test("an exact count is Balanced with no count difference line", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(200);

            await drawer.countCash(200);

            await expect(drawer.result).toHaveText("Balanced");
            const cash = await CashInOutPage.open(page, { date_from: day(0), date_to: day(0) });
            await expect(cash.row("Count difference")).toHaveCount(0);
        });

        test("a drawer counted over shows Over", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(500);

            await drawer.countCash(560, "Over by 60");

            await expect(drawer.result).toHaveText(`Over ${money(60)}`);
            const cash = await CashInOutPage.open(page, { date_from: day(0), date_to: day(0) });
            await expect(cash.row("Count difference")).toContainText(`+ ${money(60)}`);
        });

        test("each count is kept in Drawer history", async ({ page }, testInfo) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.countCash(100, "First count");
            await drawer.countCash(120, "Second count");

            await page.goto(`${historyUrl}?business_date=${day(0)}`);
            const counts = page.getByTestId("history-counts");
            await expect(counts).toContainText("First count");
            await expect(counts).toContainText("Second count");
            await expect(counts).toContainText(walletFixture(testInfo).managerName);
        });
    });

    test.describe("ending the shift", () => {
        test("End shift counts the cash, takes cash expenses and ends in one step", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(500);

            await page.getByTestId("open-end-shift").click();
            const dialog = drawer.dialog("End shift");
            // Nothing to end with until the cash is counted.
            await expect(dialog.getByTestId("confirm-end-shift")).toBeDisabled();

            await numberInput(dialog, "end-shift-counted").fill("470");
            const expenses = dialog.getByTestId("end-shift-expenses");
            if (await expenses.isVisible()) {
                await dialog.getByTestId("end-shift-add-expense").click();
                const row = dialog.getByTestId("end-shift-expense").first();
                await row.locator(".ant-select").click();
                await page.locator(".ant-select-dropdown:visible .ant-select-item-option").first().click();
                await row.getByPlaceholder("What").fill("Ice");
                await row.locator(".ant-input-number input").fill("30");
            }
            await dialog.getByTestId("end-shift-keep").click();
            const res = await drawer.save("/cash-ledger/close-shift", () => dialog.getByTestId("confirm-end-shift").click());

            expect(res.status()).toBeLessThan(400);
            await expect(page.getByTestId("shift-closed")).toBeVisible();
            await expect(drawer.expected).toHaveText(money(470));
        });

        test("taking the cash out ends the shift and records it", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(300);
            await drawer.countCash(300);

            const res = await drawer.endShift("Take the cash out");

            expect(res.status()).toBeLessThan(400);
            await expect(drawer.notice("Shift ended.")).toBeVisible();
            await expect(page.getByTestId("shift-closed")).toBeVisible();
            await expect(page.getByTestId("reopen-shift")).toBeVisible();

            const cash = await CashInOutPage.open(page, { date_from: day(0), date_to: day(0) });
            await expect(cash.row("Cash taken out at end of shift")).toContainText(`− ${money(300)}`);
        });

        test("an ended shift hides the drawer actions", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.countCash(150);
            await drawer.endShift("Leave it as the next shift's opening");

            await expect(page.getByTestId("open-set-opening")).toHaveCount(0);
            await expect(page.getByTestId("open-count-cash")).toHaveCount(0);
            await expect(page.getByTestId("open-end-shift")).toHaveCount(0);
        });

        test("leaving the cash in carries the count into opening cash", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(100);
            await drawer.countCash(275);

            await drawer.endShift("Leave it as the next shift's opening");

            await expect(page.getByTestId("shift-closed")).toBeVisible();
            await expect(drawer.expected).toHaveText(money(275));
            await page.goto(`${historyUrl}?business_date=${day(0)}`);
            await expect(page.getByTestId("history-openings")).toContainText("Set from End Shift action: save_as_opening_cash.");
        });

        test("the manager who ended the shift can reopen it", async ({ page }) => {
            const { day } = dateWindow();
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.countCash(90);
            await drawer.endShift("Leave it as the next shift's opening");

            const res = await drawer.reopenShift();

            expect(res.status()).toBeLessThan(400);
            await expect(drawer.notice("Shift reopened.")).toBeVisible();
            await expect(page.getByTestId("shift-open")).toBeVisible();
            await expect(page.getByTestId("open-count-cash")).toBeVisible();
        });
    });
});

test.describe("A shift ended by another manager", () => {
    test.use({ account: "wallet-partner" });

    test("can be reopened by a different manager, and a given reason is logged", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const closer = await serverAs("wallet-manager");
        expectSaved(await postCounted(closer, { business_date: day(0), counted_cash: 80 }), "count");
        expectSaved(await postEndShift(closer, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");

        const drawer = await DrawerPage.open(page, day(0));
        await expect(page.getByTestId("reopen-shift")).toBeVisible();

        const partner = await serverAs("wallet-partner");

        const res = await drawer.reopenShift("Forgot an expense");

        expect(res.status()).toBeLessThan(400);
        await expect(drawer.notice("Shift reopened.")).toBeVisible();
        await expect(page.getByTestId("shift-open")).toBeVisible();
        await expect(page.getByTestId("last-reopen")).toContainText("Forgot an expense");
        expect((await walletState(partner, day(0))).cashControl.is_closed).toBe(false);
    });
});
