import { test, expect } from "../support/fixtures.js";
import {
    MoneyMovementPage,
    dateWindow,
    expectSaved,
    money,
    postCounted,
    postEndShift,
    postLedger,
    postOpening,
    postReopen,
    walletFixture,
    walletState,
} from "../support/wallet.js";

test.describe("Cash control", () => {
    test.use({ account: "wallet-manager" });

    test("a fresh day shows no expected, counted or variance", async ({ page }) => {
        const { day } = dateWindow();
        const wallet = await MoneyMovementPage.open(page, day(0));

        await expect(wallet.summary("Expected")).toHaveText(money(0));
        await expect(wallet.summary("Counted")).toHaveText("—");
        await expect(wallet.summary("Variance")).toHaveText("—");
    });

    test("full cash control can be shown and hidden", async ({ page }) => {
        const { day } = dateWindow();
        const wallet = await MoneyMovementPage.open(page, day(0));

        await wallet.expandCashControl();
        await expect(page.getByText("How expected cash is calculated")).toBeVisible();

        await page.getByRole("button", { name: "Hide full cash control" }).click();
        await expect(wallet.openingCard).toBeHidden();
    });

    test("the business date picker loads the chosen date", async ({ page }) => {
        const { day } = dateWindow();
        const wallet = await MoneyMovementPage.open(page, day(0));

        const picker = page.getByPlaceholder("Select date");
        await expect(picker, "the picker should show the loaded business date").toHaveValue(day(0));

        await picker.click();
        await picker.fill(day(1));
        await picker.press("Enter");
        const reloaded = wallet.waitForReload();
        await page.getByRole("button", { name: "Load" }).click();
        await reloaded;

        await expect(page).toHaveURL(new RegExp(`business_date=${day(1)}`));
    });

    test.describe("opening cash", () => {
        test("Save opening is disabled for an empty or zero amount", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.expandCashControl();

            await wallet.openingCard.getByRole("spinbutton").fill("0");
            await expect(wallet.openingCard.getByRole("button", { name: "Save opening" })).toBeDisabled();

            await wallet.openingCard.getByRole("spinbutton").fill("250");
            await expect(wallet.openingCard.getByRole("button", { name: "Save opening" })).toBeEnabled();
        });

        test("saving opening cash updates expected cash and records who saved it", async ({ page }, testInfo) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const res = await wallet.saveOpening(500, "Float from safe");

            expect(res.status()).toBeLessThan(400);
            await expect(wallet.notice("Opening cash saved.")).toBeVisible();
            await expect(wallet.summary("Expected")).toHaveText(money(500));
            await expect(wallet.openingCard, "the panel stays open after saving").toBeVisible();
            await expect(page.getByText(`Opening last saved by ${walletFixture(testInfo).managerName}`)).toBeVisible();
            await page.getByText("Opening cash change history").click();
            await expect(page.getByText("Float from safe")).toBeVisible();
        });

        test("changing opening cash keeps an audit trail", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            await wallet.saveOpening(300);
            await wallet.saveOpening(350, "Counted again");

            await wallet.expandCashControl();
            await page.getByText("Opening cash change history").click();
            await expect(page.getByText(`${money(300)} → ${money(350)}`)).toBeVisible();
            await expect(wallet.summary("Expected")).toHaveText(money(350));
        });

        test("suggests opening cash from the previous day's count", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 420 }), "count day 0");

            const wallet = await MoneyMovementPage.open(page, day(1));
            await wallet.expandCashControl();

            await expect(page.getByText(`Suggested from previous counted cash on ${day(0)}.`)).toBeVisible();
            await expect(wallet.openingCard.getByRole("spinbutton")).toHaveValue("420.00");
        });
    });

    test.describe("counted cash", () => {
        test("Save counted cash is disabled until an amount is entered", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.expandCashControl();

            await expect(wallet.countedCard.getByRole("button", { name: "Save counted cash" })).toBeDisabled();
            await wallet.countedCard.getByRole("spinbutton").fill("100");
            await expect(wallet.countedCard.getByRole("button", { name: "Save counted cash" })).toBeEnabled();
        });

        test("an empty drawer (zero) can be counted", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const res = await wallet.saveCounted(0);

            expect(res.status()).toBeLessThan(400);
            await expect(wallet.summary("Counted")).toHaveText(money(0));
        });

        test("a short count shows a negative variance and posts a variance ledger line", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveOpening(500);

            const res = await wallet.saveCounted(450, "Short by 50");

            expect(res.status()).toBeLessThan(400);
            await expect(wallet.notice("Counted cash saved.")).toBeVisible();
            await expect(wallet.summary("Counted")).toHaveText(money(450));
            await expect(wallet.summary("Variance")).toHaveText(money(-50));

            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.row("Counted cash variance")).toHaveCount(1);
            await expect(wallet.row("Counted cash variance")).toContainText(`−${money(50)}`);
        });

        test("recounting replaces the variance line instead of adding another", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveOpening(500);
            await wallet.saveCounted(450);

            await wallet.saveCounted(480);

            await expect(wallet.summary("Variance")).toHaveText(money(-20));
            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.row("Counted cash variance")).toHaveCount(1);
            await expect(wallet.row("Counted cash variance")).toContainText(`−${money(20)}`);
        });

        test("an exact count has zero variance and no variance line", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveOpening(200);

            await wallet.saveCounted(200);

            await expect(wallet.summary("Variance")).toHaveText(money(0));
            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.row("Counted cash variance")).toHaveCount(0);
        });

        test("each count is kept in the submission history", async ({ page }, testInfo) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveCounted(100, "First count");
            await wallet.saveCounted(120, "Second count");

            await wallet.expandCashControl();
            await page.getByText(`Count submissions for ${day(0)}`).click();

            await expect(page.getByText("First count")).toBeVisible();
            await expect(page.getByText("Second count")).toBeVisible();
            await expect(page.locator("p").filter({ hasText: `Counted by ${walletFixture(testInfo).managerName}` })).toBeVisible();
        });

        test("expected cash includes manual ledger movements for the day", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postOpening(api, { business_date: day(0), opening_cash: 1000 }), "opening");
            expectSaved(await postLedger(api, { direction: "in", amount: 250, kind: "cash_sale_topup", movement_date: day(0) }), "cash in");
            expectSaved(
                await postLedger(api, { direction: "out", amount: 100, kind: "owner_draw", draw_source: "cash_register", movement_date: day(0) }),
                "cash out",
            );

            const wallet = await MoneyMovementPage.open(page, day(0));

            await expect(wallet.summary("Expected")).toHaveText(money(1150));
            await wallet.expandCashControl();
            await page.getByText("How expected cash is calculated").click();
            await expect(page.getByText(`${money(1000)} opening + ${money(0)} cash sales + ${money(250)} manual in − ${money(100)} manual out = ${money(1150)} expected`)).toBeVisible();
        });
    });

    test.describe("end shift", () => {
        test("End Shift asks for a count first", async ({ page }) => {
            const { day } = dateWindow();
            await MoneyMovementPage.open(page, day(0));

            await page.getByRole("button", { name: "End Shift" }).click();

            const dialog = page.getByRole("dialog").filter({ hasText: "Count cash first before End Shift" });
            await expect(dialog).toBeVisible();
            await dialog.getByRole("button", { name: "Go to Submit Counted Cash" }).click();
            await expect(page.getByText("Submit counted cash", { exact: true })).toBeInViewport();
        });

        test("cash out now closes the shift and posts the cash out", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveOpening(300);
            await wallet.saveCounted(300);

            const res = await wallet.endShift("Cash out now");

            expect(res.status()).toBeLessThan(400);
            await expect(wallet.notice("Shift closed.")).toBeVisible();
            await expect(page.getByText("Shift closed for this date")).toBeVisible();
            await expect(page.getByRole("button", { name: "Reopen Shift" })).toBeVisible();

            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.row("End shift cash out")).toContainText(`−${money(300)}`);
        });

        test("a closed shift locks the forms and ledger entry", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveCounted(150);
            await wallet.endShift("Save as opening cash");
            await wallet.expandCashControl();

            await expect(wallet.openingCard.getByRole("spinbutton")).toBeDisabled();
            await expect(wallet.countedCard.getByRole("spinbutton")).toBeDisabled();
            await expect(page.getByRole("button", { name: /Add entry/ })).toBeDisabled();
            await expect(page.getByRole("button", { name: "End Shift" })).toHaveCount(0);
        });

        test("save as opening cash carries the count into opening cash", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveOpening(100);
            await wallet.saveCounted(275);

            await wallet.endShift("Save as opening cash");

            await expect(page.getByText("Shift closed for this date")).toBeVisible();
            await expect(wallet.summary("Expected")).toHaveText(money(275));
            await wallet.expandCashControl();
            await page.getByText("Opening cash change history").click();
            await expect(page.getByText("Set from End Shift action: save_as_opening_cash.")).toBeVisible();
        });

        test("the manager who closed the shift can reopen it", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveCounted(90);
            await wallet.endShift("Save as opening cash");

            const res = await wallet.reopenShift();

            expect(res.status()).toBeLessThan(400);
            await expect(wallet.notice("Shift reopened.")).toBeVisible();
            await expect(page.getByText("Shift closed for this date")).toHaveCount(0);
            await expect(page.getByRole("button", { name: /Add entry/ })).toBeEnabled();
        });
    });
});

test.describe("A shift closed by another manager", () => {
    test.use({ account: "wallet-partner" });

    test("can't be reopened by a different manager", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const closer = await serverAs("wallet-manager");
        expectSaved(await postCounted(closer, { business_date: day(0), counted_cash: 80 }), "count");
        expectSaved(await postEndShift(closer, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");

        await MoneyMovementPage.open(page, day(0));

        await expect(page.getByText("Only the user who closed this shift can reopen it.")).toBeVisible();
        await expect(page.getByRole("button", { name: "Reopen Shift" })).toHaveCount(0);

        const partner = await serverAs("wallet-partner");
        const res = await postReopen(partner, { business_date: day(0) });
        expect(res.status).toBe(403);
        expect((await walletState(partner, day(0))).cashControl.is_closed).toBe(true);
    });
});
