import { test, expect } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage, freshWorkerCart } from "../support/sales.js";

/**
 * Split payment: one sale paid in parts (here cash + the E2E GCash e-wallet). Proceed stays off
 * until the parts cover the total; only cash may come to more (the rest is change).
 */

const { products } = E2E;

test.describe("Split payment (classic layout)", () => {
    test.use({ account: "worker-cashier" });

    test.beforeEach(async ({ serverAs }, testInfo) => {
        await freshWorkerCart(serverAs, testInfo);
    });

    async function openSplit(page) {
        const sales = await SalesPage.open(page, { layout: "classic" });
        await sales.addProduct(products.burger.name); // ₱100
        await sales.goToCheckout();
        await sales.chooseOtherPayment("Split payment");
        await expect(page.getByTestId("split-payment")).toBeVisible();

        return sales;
    }

    async function chooseGcash(page, row) {
        await page.getByTestId("split-payment").getByRole("button", { name: /Choose e-wallet|E2E GCash/ }).nth(row).click();
        const dialog = page.getByRole("dialog", { name: "Which e-wallet?" });
        await dialog.getByText("E2E GCash", { exact: true }).click();
        await dialog.getByRole("button", { name: "Use selected e-wallet" }).click();
        await expect(dialog).toBeHidden();
    }

    test("cash and GCash together pay the sale, with change off the cash", async ({ page }) => {
        const sales = await openSplit(page);
        const panel = page.getByTestId("split-payment");

        await panel.getByLabel("Payment 1 amount").fill("70");
        await panel.getByLabel("Payment 2 amount").fill("40");
        await chooseGcash(page, 0);
        await panel.getByLabel("Payment 2 reference no.").fill("GC-777");

        await expect(panel).toContainText("Change: ₱10.00");
        await expect(sales.proceedButton).toBeEnabled();

        const paid = page.waitForRequest((r) => r.method() === "POST" && r.url().includes("/payments"));
        const res = await sales.pay();
        const body = (await paid).postDataJSON();

        expect(res?.status()).toBe(200);
        expect(body.payment_method).toBe("split");
        expect(body.payments).toEqual([
            { method: "cash", amount: 70 },
            expect.objectContaining({ method: "e-wallet", amount: 40, payment_reference: "GC-777" }),
        ]);
        await expect(sales.notice("Payment Successful!")).toBeVisible();
    });

    test("Proceed stays off while the parts are short of the total", async ({ page }) => {
        const sales = await openSplit(page);
        const panel = page.getByTestId("split-payment");

        await panel.getByLabel("Payment 1 amount").fill("30");
        await panel.getByLabel("Payment 2 amount").fill("40");
        await chooseGcash(page, 0);

        await expect(panel).toContainText("Remaining: ₱30.00");
        await expect(sales.proceedButton).toBeDisabled();

        // "Rest" puts what is left on that part.
        await panel.getByRole("button", { name: "Rest" }).first().click();
        await expect(panel.getByLabel("Payment 1 amount")).toHaveValue("60.00");
        await expect(sales.proceedButton).toBeEnabled();
    });

    test("the e-wallet can't come to more than the total", async ({ page }) => {
        const sales = await openSplit(page);
        const panel = page.getByTestId("split-payment");

        await panel.getByLabel("Payment 1 amount").fill("10");
        await panel.getByLabel("Payment 2 amount").fill("150");
        await chooseGcash(page, 0);

        await expect(panel).toContainText("Only cash can be more than the total");
        await expect(sales.proceedButton).toBeDisabled();
    });
});
