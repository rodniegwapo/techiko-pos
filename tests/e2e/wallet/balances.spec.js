import { test, expect } from "../support/fixtures.js";
import {
    DrawerPage,
    balancesUrl,
    dateWindow,
    expectSaved,
    historyUrl,
    money,
    numberInput,
    postCounted,
    postLedger,
    postOpening,
    todayYmd,
    walletFixture,
} from "../support/wallet.js";

/**
 * Where money is (the drawer and each payment channel) and Drawer history (checking back).
 * Each test takes its own store and window of business dates (support/wallet.js).
 */

test.describe("Where money is", () => {
    test.use({ account: "wallet-manager" });

    test("the drawer card shows today's Expected, the same as Today's drawer", async ({ page }) => {
        const drawer = await DrawerPage.open(page);
        const expected = await drawer.expected.innerText();

        await page.goto(balancesUrl);

        await expect(page.getByTestId("drawer-balance-amount")).toHaveText(expected);
        await page.getByTestId("drawer-balance").getByRole("button", { name: "Spot check" }).click();
        await expect(page).toHaveURL(new RegExp("/wallet/drawer"));
    });

    test("setting a channel's actual balance records the difference", async ({ page }, testInfo) => {
        const { cardTypeId } = walletFixture(testInfo);
        await page.goto(balancesUrl);

        const card = page.getByTestId(`channel-balance-${cardTypeId}`);
        const before = Number((await card.getByTestId("channel-balance-amount").innerText()).replace(/[^\d.-]/g, ""));
        await card.getByRole("button", { name: "Set actual" }).click();
        const dialog = page.getByRole("dialog");
        await numberInput(dialog, "channel-actual-balance").fill(String(before + 25));
        await expect(dialog).toContainText(`Records a correction of +${money(25)} today.`);
        await dialog.getByRole("button", { name: "Save" }).click();

        await expect(card.getByTestId("channel-balance-amount")).toHaveText(money(before + 25));
    });

    test("Manage channels opens the payment channels", async ({ page }) => {
        await page.goto(balancesUrl);

        await page.getByTestId("balances-tab-channels").click();

        await expect(page).toHaveURL(/\/payment-card-types/);
        await expect(page.getByRole("heading", { name: "Payment channels" })).toBeVisible();
        await expect(page.getByTestId("drawer-expected"), "the drawer has its own page now").toHaveCount(0);
    });
});

test.describe("Drawer history", () => {
    test.use({ account: "wallet-manager" });

    test("checks the drawer since its last count", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const api = await serverAs("wallet-manager");
        expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 400 }), "count day 0");
        expectSaved(await postOpening(api, { business_date: day(2), opening_cash: 400 }), "opening day 2");
        expectSaved(await postLedger(api, { direction: "in", amount: 50, kind: "cash_sale_topup", movement_date: day(1) }), "cash in day 1");
        expectSaved(await postCounted(api, { business_date: day(2), counted_cash: 450 }), "count day 2");

        await page.goto(`${historyUrl}?business_date=${day(2)}`);

        const check = page.getByTestId("history-since-last-count");
        await expect(check).toContainText(`Counted ${money(400)} on ${day(0)}`);
        await expect(check).toContainText(`should hold ${money(450)}`);
        await expect(check).toContainText("Balanced");
    });

    test("a day without counts says so", async ({ page }) => {
        const { day } = dateWindow();

        await page.goto(`${historyUrl}?business_date=${day(0)}`);

        await expect(page.getByTestId("history-counts")).toContainText("No counts on this day.");
        await expect(page.getByTestId("history-openings")).toContainText("wasn't changed");
        expect(todayYmd()).toBeTruthy();
    });
});
