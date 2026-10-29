import { test, expect } from "../support/fixtures.js";
import {
    CashInOutPage,
    DrawerPage,
    dateWindow,
    expectSaved,
    futureYmd,
    ledgerState,
    money,
    postCounted,
    postEndShift,
    postLedger,
    todayYmd,
    walletFixture,
} from "../support/wallet.js";

test.describe("Cash in & out", () => {
    test.use({ account: "wallet-manager" });

    test("a day with nothing recorded says so", async ({ page }) => {
        const { day } = dateWindow();
        const cash = await CashInOutPage.open(page);

        await cash.filter({ from: day(0), to: day(0) });

        await expect(page.getByText("Nothing recorded for these dates.")).toBeVisible();
    });

    test.describe("adding lines", () => {
        test("cash in records added float with the manager as author", async ({ page }, testInfo) => {
            const { day } = dateWindow();
            const cash = await CashInOutPage.open(page);

            const { res, dialog } = await cash.add("in", { amount: 150, date: day(0), notes: "Float top-up" });

            expect(res?.status(), "line saved").toBeLessThan(400);
            await expect(dialog).toBeHidden();
            await expect(cash.toast("Saved.")).toBeVisible();

            await cash.filter({ from: day(0), to: day(0) });
            const row = cash.row("Added float");
            await expect(row).toHaveCount(1);
            await expect(row).toContainText(`+ ${money(150)}`);
            await expect(row).toContainText("Float top-up");
            await expect(row).toContainText("Cash drawer");
            await expect(row).toContainText(walletFixture(testInfo).managerName);
        });

        test("the Cash in dialog starts as added float, dated today", async ({ page }) => {
            const cash = await CashInOutPage.open(page);

            const dialog = await cash.openDialog("in");

            await expect(dialog).toContainText("Cash in");
            await expect(dialog.locator(".ant-radio-button-wrapper-checked")).toHaveText("Added float");
            await expect(dialog.locator(".ant-picker input")).toHaveValue(todayYmd());
        });

        test("cash out is the owner taking cash from the drawer", async ({ page }) => {
            const { day } = dateWindow();
            const cash = await CashInOutPage.open(page);

            const dialog = await cash.openDialog("out");
            await expect(dialog.locator(".ant-radio-button-wrapper-checked")).toHaveText("Owner took cash");

            await cash.fill(dialog, { amount: 80, date: day(0) });
            const res = await cash.save(dialog);

            expect(res?.status()).toBeLessThan(400);
            await cash.filter({ from: day(0), to: day(0) });
            await expect(cash.row("Owner took cash")).toContainText(`− ${money(80)}`);
            await expect(cash.row("Owner took cash")).toContainText("Cash drawer");
        });

        test("the owner taking from a payment channel needs the channel", async ({ page }) => {
            const { day } = dateWindow();
            const cash = await CashInOutPage.open(page);
            const dialog = await cash.openDialog("out");
            await cash.fill(dialog, { amount: 60, date: day(0), from: "A payment channel" });

            const blocked = await cash.save(dialog);

            expect(blocked, "no request without a channel").toBeNull();
            await expect(cash.toast("Choose the payment channel.")).toBeVisible();

            await cash.fill(dialog, { channel: "E2E Wallet Visa" });
            const res = await cash.save(dialog);

            expect(res?.status()).toBeLessThan(400);
            await cash.filter({ from: day(0), to: day(0) });
            await expect(cash.row("Owner took cash")).toContainText("E2E Wallet Visa");
        });

        test("a correction takes cash out of the drawer", async ({ page }) => {
            const { day } = dateWindow();
            const cash = await CashInOutPage.open(page);

            const { res } = await cash.add("out", { type: "Correction", amount: 25.5, date: day(0), notes: "Miscount" });

            expect(res?.status()).toBeLessThan(400);
            await cash.filter({ from: day(0), to: day(0) });
            const row = cash.row("Correction");
            await expect(row).toContainText(`− ${money(25.5)}`);
            await expect(row).toContainText("Cash drawer");
            await expect(row).toContainText("Miscount");
        });

        test("moving money into a channel is recorded on the channel", async ({ page }) => {
            const { day } = dateWindow();
            const cash = await CashInOutPage.open(page);

            const { res } = await cash.add("move", { direction: "Into the channel", channel: "E2E Wallet Visa", amount: 70, date: day(0) });

            expect(res?.status()).toBeLessThan(400);
            await cash.filter({ from: day(0), to: day(0) });
            const row = cash.row("Moved into E2E Wallet Visa");
            await expect(row).toContainText(`+ ${money(70)}`);
            await expect(row).toContainText("E2E Wallet Visa");
        });

        test("an empty amount is stopped before saving", async ({ page }) => {
            const cash = await CashInOutPage.open(page);
            const dialog = await cash.openDialog("in");

            const res = await cash.save(dialog);

            expect(res).toBeNull();
            await expect(cash.toast("Enter the amount.")).toBeVisible();
            await expect(dialog).toBeVisible();
        });

        test("a future date can't be picked", async ({ page }) => {
            const cash = await CashInOutPage.open(page);
            const dialog = await cash.openDialog("in");

            await cash.fill(dialog, { date: futureYmd() });

            await expect(dialog.locator(".ant-picker input"), "the picker keeps today").toHaveValue(todayYmd());
        });

        test("a server-side rejection is shown to the manager", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 10 }), "count");
            expectSaved(await postEndShift(api, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");
            const cash = await CashInOutPage.open(page);
            const dialog = await cash.openDialog("in");
            await cash.fill(dialog, { amount: 10, date: day(0), notes: "Rejected closed day" });

            const res = await cash.save(dialog);

            expect(res, "the line was sent").not.toBeNull();
            const state = await ledgerState(api);
            expect(state.ledger.movements.data.map((m) => m.notes), "nothing was saved").not.toContain("Rejected closed day");
            await expect(page.getByText(/Shift is closed/i)).toBeVisible();
            await expect(dialog, "the dialog stays open to correct it").toBeVisible();
        });
    });

    test.describe("filters", () => {
        /** Three lines across two days of this test's window; the transfer is on the channel. */
        async function seedEntries(api, day, cardTypeId) {
            expectSaved(await postLedger(api, { direction: "in", amount: 100, kind: "cash_sale_topup", movement_date: day(0), notes: "F-topup" }), "topup");
            expectSaved(
                await postLedger(api, { direction: "out", amount: 30, kind: "owner_draw", draw_source: "cash_register", movement_date: day(0), notes: "F-draw" }),
                "draw",
            );
            expectSaved(
                await postLedger(api, {
                    direction: "in", amount: 70, kind: "ewallet_transfer_in", payment_card_type_id: cardTypeId, movement_date: day(1), notes: "F-ewallet",
                }),
                "ewallet",
            );
        }

        test("the date range limits the lines", async ({ page, serverAs }, testInfo) => {
            const { day } = dateWindow();
            await seedEntries(await serverAs("wallet-manager"), day, walletFixture(testInfo).cardTypeId);
            const cash = await CashInOutPage.open(page);

            await cash.filter({ from: day(0), to: day(0) });
            await expect(cash.rows).toHaveCount(2);

            await cash.filter({ from: day(0), to: day(1) });
            await expect(cash.rows).toHaveCount(3);
        });

        test("the type filter shows only that type", async ({ page, serverAs }, testInfo) => {
            const { day } = dateWindow();
            await seedEntries(await serverAs("wallet-manager"), day, walletFixture(testInfo).cardTypeId);
            const cash = await CashInOutPage.open(page);

            await cash.filter({ from: day(0), to: day(1), type: "Added float" });

            await expect(cash.rows).toHaveCount(1);
            await expect(cash.rows.first()).toContainText("F-topup");
            await expect(page).toHaveURL(/kind=cash_sale_topup/);
        });

        test("twenty lines to a page, keeping the filters", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            for (let n = 1; n <= 21; n++) {
                expectSaved(await postLedger(api, { direction: "in", amount: n, kind: "adjustment", movement_date: day(0), notes: `E2E paging ${n}` }), `line ${n}`);
            }
            const cash = await CashInOutPage.open(page);
            await cash.filter({ from: day(0), to: day(0) });
            await expect(cash.rows).toHaveCount(20);

            const loaded = page.waitForResponse((r) => r.request().method() === "GET" && new URL(r.url()).searchParams.get("page") === "2");
            await page.locator(".ant-pagination-item-2").click();
            await loaded;

            await expect(cash.rows).toHaveCount(1);
            await expect(cash.rows.first()).toContainText(money(1));
            await expect(page).toHaveURL(new RegExp(`date_from=${day(0)}`));
            await expect(page).toHaveURL(new RegExp(`date_to=${day(0)}`));
        });
    });

    test.describe("lines Today's drawer writes", () => {
        test("opening cash, the count difference and the end-of-shift cash out are labelled", async ({ page, serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postLedger(api, { direction: "in", amount: 1, kind: "adjustment", movement_date: day(0) }), "marker");
            const drawer = await DrawerPage.open(page, day(0));
            await drawer.setOpening(100);
            await drawer.countCash(90);
            await drawer.endShift("Cash out to Cash on hand");

            const cash = await CashInOutPage.open(page, { date_from: day(0), date_to: day(0) });

            await expect(cash.row("Opening cash")).toContainText(`+ ${money(100)}`);
            await expect(cash.row("Count difference")).toContainText(`− ${money(11)}`);
            await expect(cash.row("Cash out to Cash on hand at end of day")).toContainText(`− ${money(90)}`);
            // System tokens are shown as labels, never as raw notes.
            await expect(page.getByText(/AUTO_CC_/)).toHaveCount(0);
        });
    });

    test.describe("an ended shift", () => {
        test("refuses lines for that day", async ({ serverAs }) => {
            const { day } = dateWindow();
            const api = await serverAs("wallet-manager");
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 50 }), "count");
            expectSaved(await postEndShift(api, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");

            const res = await postLedger(api, { direction: "in", amount: 5, kind: "cash_sale_topup", movement_date: day(0) });

            expect(res.status).toBe(422);
        });
    });
});
