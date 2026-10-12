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

        // The fill button puts what is left on that part.
        await panel.getByRole("button", { name: "Fill the remaining amount for payment 1" }).click();
        await expect(panel.getByLabel("Payment 1 amount")).toHaveValue("60.00");
        await expect(sales.proceedButton).toBeEnabled();
    });

    test("says why it can't be charged before the amounts are in", async ({ page }) => {
        const sales = await openSplit(page);
        const panel = page.getByTestId("split-payment");

        await expect(panel.getByTestId("split-problem")).toHaveText("Enter an amount for each payment.");
        await expect(sales.proceedButton).toBeDisabled();
    });

    test("a store without an e-wallet starts the split as cash and card", async ({ page }) => {
        // Channels are set up per store; this store's list has a card only.
        await page.route("**/payment-card-types/list**", (route) =>
            route.fulfill({ json: { data: [{ id: 999001, name: "E2E Only Card", kind: "card", is_active: true }] } }),
        );
        await openSplit(page);
        const panel = page.getByTestId("split-payment");

        // Card is picked up as the second method, and the store's one card is chosen for it.
        await expect(panel.getByRole("button", { name: "E2E Only Card" })).toBeVisible();
        await panel.getByLabel("Payment 2 method").click();
        const options = page.locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option");
        await expect(options).toHaveText(["Cash", "Card"]);
        await page.keyboard.press("Escape");
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
