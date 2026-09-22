import { test, expect } from "../support/fixtures.js";
import {
    MoneyMovementPage,
    dateWindow,
    expectSaved,
    futureYmd,
    money,
    postCounted,
    postEndShift,
    postLedger,
    walletFixture,
    walletState,
} from "../support/wallet.js";

test.describe("Ledger movements", () => {
    test.use({ account: "wallet-manager" });

    test("a new day's filter shows no entries and a zero ledger net", async ({ page }) => {
        const { day } = dateWindow();
        const wallet = await MoneyMovementPage.open(page, day(0));

        await wallet.filter({ from: day(0), to: day(0) });

        await expect(page.getByText("No ledger entries yet.")).toBeVisible();
        await expect(wallet.headline("Ledger net")).toHaveText(money(0));
    });

    test.describe("adding entries", () => {
        test("cash in records a top-up with the manager as author", async ({ page }, testInfo) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const { res, dialog } = await wallet.addEntry("Cash in", { amount: 150, date: day(0), notes: "Float top-up" });

            expect(res?.status(), "ledger entry saved").toBeLessThan(400);
            await expect(dialog).toBeHidden();
            await expect(wallet.toast("Ledger entry saved.")).toBeVisible();

            await wallet.filter({ from: day(0), to: day(0) });
            const row = wallet.row("Cash / float top-up");
            await expect(row).toHaveCount(1);
            await expect(row).toContainText(`+${money(150)}`);
            await expect(row).toContainText("Float top-up");
            await expect(row).toContainText("Cash register");
            await expect(row).toContainText(walletFixture(testInfo).managerName);
            await expect(wallet.headline("Ledger net")).toHaveText(money(150));
        });

        test("the Cash in dialog starts as a cash-in top-up", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const dialog = await wallet.openEntryDialog("Cash in");

            await expect(dialog).toContainText("Add cash in entry");
            await expect(dialog.locator(".ant-radio-button-wrapper-checked")).toHaveText("Cash in");
            await expect(dialog).toContainText("Cash / float top-up");
        });

        test("the entry date defaults to the business date being viewed", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const dialog = await wallet.openEntryDialog("Cash in");

            await expect(dialog.locator('input[type="date"]')).toHaveValue(day(0));
        });

        test("cash out is an owner withdrawal from the cash register", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const dialog = await wallet.openEntryDialog("Cash out");
            await expect(dialog).toContainText("Owner withdrawal");
            // Owner withdrawals can only take cash out.
            await expect(dialog.locator(".ant-radio-button-wrapper").filter({ hasText: "Cash in" })).toHaveClass(/disabled/);

            await wallet.fillEntry(dialog, { amount: 80, date: day(0) });
            const res = await wallet.saveEntry(dialog);

            expect(res?.status()).toBeLessThan(400);
            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.row("Owner withdrawal")).toContainText(`−${money(80)}`);
            await expect(wallet.row("Owner withdrawal")).toContainText("Cash register");
            await expect(wallet.headline("Ledger net")).toHaveText(money(-80));
        });

        test("an owner withdrawal from a card rail requires choosing the rail", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            const dialog = await wallet.openEntryDialog("Cash out");
            await wallet.fillEntry(dialog, { amount: 60, date: day(0), withdrawFrom: "Terminal / card rail" });

            const blocked = await wallet.saveEntry(dialog);

            expect(blocked, "no request without a rail").toBeNull();
            await expect(wallet.toast("Choose a terminal / card rail.")).toBeVisible();

            await wallet.fillEntry(dialog, { rail: "E2E Wallet Visa" });
            const res = await wallet.saveEntry(dialog);

            expect(res?.status()).toBeLessThan(400);
            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.row("Owner withdrawal")).toContainText("E2E Wallet Visa");
        });

        test("other entry records an adjustment on a card rail", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));

            const { res } = await wallet.addEntry("Other entry", {
                kind: "Adjustment",
                direction: "Cash out",
                amount: 25.5,
                rail: "E2E Wallet Visa",
                date: day(0),
                notes: "Terminal fee",
            });

            expect(res?.status()).toBeLessThan(400);
            await wallet.filter({ from: day(0), to: day(0) });
            const row = wallet.row("Adjustment");
            await expect(row).toContainText(`−${money(25.5)}`);
            await expect(row).toContainText("E2E Wallet Visa");
            await expect(row).toContainText("Terminal fee");
        });

        test("an empty amount is rejected before saving", async ({ page }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            const dialog = await wallet.openEntryDialog("Cash in");

            const res = await wallet.saveEntry(dialog);

            expect(res).toBeNull();
            await expect(wallet.toast("Enter a valid amount.")).toBeVisible();
            await expect(dialog).toBeVisible();
        });

        test("a server-side rejection is shown to the manager", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const wallet = await MoneyMovementPage.open(page, day(0));
            const dialog = await wallet.openEntryDialog("Cash in");
            // The date input has max=today, but typing a future date is still possible.
            await wallet.fillEntry(dialog, { amount: 10, date: futureYmd(), notes: "Rejected future entry" });

            const res = await wallet.saveEntry(dialog);

            expect(res, "the entry was sent").not.toBeNull();
            const state = await walletState(await serverAs("wallet-manager"), day(0));
            expect(state.ledger.movements.data.map((m) => m.notes), "nothing was saved").not.toContain("Rejected future entry");
            await expect(
                page.getByText(/movement date .*before or equal to today/i),
                "the manager should be told why the entry wasn't saved",
            ).toBeVisible();
            await expect(dialog, "the dialog stays open to correct the entry").toBeVisible();
        });

        test("running cash is the all-time total and grows with each entry", async ({ page, serverAs }, testInfo) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            const before = (await walletState(api, day(0))).runningCashBalance;
            const wallet = await MoneyMovementPage.open(page, day(0));

            await wallet.addEntry("Cash in", { amount: 40, date: day(0) });

            const after = Math.round((before + 40) * 100) / 100;
            await expect.poll(async () => (await walletState(api, day(0))).runningCashBalance).toBe(after);
            await page.reload();
            await expect(wallet.headline("Running cash")).toHaveText(money(after));
            expect(walletFixture(testInfo).locationId).toBeTruthy();
        });
    });

    test.describe("filters", () => {
        /** Seeds three entries across two days of this test's window. */
        async function seedEntries(api, day) {
            expectSaved(await postLedger(api, { direction: "in", amount: 100, kind: "cash_sale_topup", movement_date: day(0), notes: "F-topup" }), "topup");
            expectSaved(
                await postLedger(api, { direction: "out", amount: 30, kind: "owner_draw", draw_source: "cash_register", movement_date: day(0), notes: "F-draw" }),
                "draw",
            );
            expectSaved(await postLedger(api, { direction: "in", amount: 70, kind: "ewallet_transfer_in", movement_date: day(1), notes: "F-ewallet" }), "ewallet");
        }

        test("the date range limits rows and the ledger net", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            await seedEntries(await serverAs("wallet-manager"), day);
            const wallet = await MoneyMovementPage.open(page, day(0));

            await wallet.filter({ from: day(0), to: day(0) });
            await expect(wallet.rows).toHaveCount(2);
            await expect(wallet.headline("Ledger net")).toHaveText(money(70));

            await wallet.filter({ from: day(0), to: day(1) });
            await expect(wallet.rows).toHaveCount(3);
            await expect(wallet.headline("Ledger net")).toHaveText(money(140));
        });

        test("the kind filter shows only that kind", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            await seedEntries(await serverAs("wallet-manager"), day);
            const wallet = await MoneyMovementPage.open(page, day(0));

            await wallet.filter({ from: day(0), to: day(1), kind: "Transfer in (e-wallet)" });

            await expect(wallet.rows).toHaveCount(1);
            await expect(wallet.rows.first()).toContainText("F-ewallet");
            await expect(page).toHaveURL(/kind=ewallet_transfer_in/);
        });

        test("the rail filter separates cash register and card rail entries", async ({ page, serverAs }, testInfo) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            await seedEntries(api, day);
            expectSaved(
                await postLedger(api, {
                    direction: "out",
                    amount: 15,
                    kind: "adjustment",
                    payment_card_type_id: walletFixture(testInfo).cardTypeId,
                    movement_date: day(0),
                    notes: "F-card",
                }),
                "card adjustment",
            );
            const wallet = await MoneyMovementPage.open(page, day(0));

            await wallet.filter({ from: day(0), to: day(1), rail: "E2E Wallet Visa" });
            await expect(wallet.rows).toHaveCount(1);
            await expect(wallet.rows.first()).toContainText("F-card");

            await wallet.filter({ rail: "Cash register (no card rail)" });
            await expect(wallet.rows).toHaveCount(3);
            await expect(wallet.row("F-card")).toHaveCount(0);
        });
    });

    test.describe("automatic entries", () => {
        test("opening cash, count variance and end-shift cash out are labelled", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postLedger(api, { direction: "in", amount: 1, kind: "adjustment", movement_date: day(0) }), "marker");
            const wallet = await MoneyMovementPage.open(page, day(0));
            await wallet.saveOpening(100);
            await wallet.saveCounted(90);
            await wallet.endShift("Cash out now");

            await wallet.filter({ from: day(0), to: day(0) });

            await expect(wallet.row("Opening cash (saved)")).toContainText(`+${money(100)}`);
            await expect(wallet.row("Counted cash variance")).toContainText(`−${money(11)}`);
            await expect(wallet.row("End shift cash out")).toContainText(`−${money(90)}`);
            // System tokens are shown as labels, never as raw notes.
            await expect(page.getByText(/AUTO_CC_/)).toHaveCount(0);
        });
    });

    test.describe("closed shift", () => {
        test("Add entry is disabled for a closed date", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 50 }), "count");
            expectSaved(await postEndShift(api, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");

            await MoneyMovementPage.open(page, day(0));

            await expect(page.getByRole("button", { name: /Add entry/ })).toBeDisabled();
        });
    });
});
