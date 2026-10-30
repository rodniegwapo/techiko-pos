import { test, expect } from "../support/fixtures.js";
import { cashInOutUrl } from "../support/wallet.js";

/** Cash out shows what there is to take, under the amount, and flags an amount larger than that. */
test.use({ account: "wallet-manager" });

test("cash out shows what the drawer holds and warns when the amount is more", async ({ page }) => {
    await page.goto(cashInOutUrl);
    await page.getByTestId("cash-movement").click();
    await page.getByRole("dialog").getByTestId("add-cash-out").click();

    const available = page.getByTestId("cash-available");
    await expect(available).toContainText("Available in the drawer");

    await page.getByTestId("cash-amount").fill("99999999");
    await expect(available).toContainText("This is more than that.");
});

test("cash in has nothing to take from, so shows no balance", async ({ page }) => {
    await page.goto(cashInOutUrl);
    await page.getByTestId("cash-movement").click();
    await expect(page.getByTestId("cash-amount")).toBeVisible();
    await expect(page.getByTestId("cash-available")).toHaveCount(0);
});
