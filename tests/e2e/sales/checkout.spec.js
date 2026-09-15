import { test, expect } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage, cartState, customer, fixtureIds, freshWorkerCart, stockOf } from "../support/sales.js";

const { products, customers, cards, discounts } = E2E;

for (const layout of ["classic", "coffeeshop"]) {
    test.describe(`Checkout (${layout} layout)`, () => {
        test.use({ account: "worker-cashier" });

        /** HTTP session for the same cashier, with an empty cart. */
        let cashierApi;
        let cashierId;

        test.beforeEach(async ({ serverAs }, testInfo) => {
            ({ api: cashierApi, userId: cashierId } = await freshWorkerCart(serverAs, testInfo));
        });

        test.describe("cash", () => {
            test("Proceed Payment stays disabled until the amount received covers the total", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.increase(products.burger.name);
                await sales.goToCheckout();

                await sales.amountReceived.fill("150");
                await expect(sales.proceedButton).toBeDisabled();

                await sales.amountReceived.fill("250");
                await expect(sales.proceedButton).toBeEnabled();
                await expect(page.locator('input[readonly]').last()).toHaveValue("₱50.00");
            });

            test("completes the sale, clears the cart and deducts stock", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                const stockBefore = await stockOf(cashierApi, products.fries.name);
                await sales.addProduct(products.fries.name);
                await sales.increase(products.fries.name);
                const saleId = (await cartState(cashierApi, cashierId)).sale.id;
                await sales.goToCheckout();

                const res = await sales.pay({ amountReceived: 100 });

                expect(res?.status(), "payment response").toBe(200);
                await expect(sales.notice("Payment Successful!")).toBeVisible();
                await expect(sales.quantityButton(products.fries.name)).toHaveCount(0);

                const pending = await cartState(cashierApi, cashierId);
                expect(pending.sale?.id, "the paid sale is no longer the pending cart").not.toBe(saleId);
                // Other workers may sell fries at the same time, so stock must have dropped by at least 2.
                expect(await stockOf(cashierApi, products.fries.name)).toBeLessThanOrEqual(stockBefore - 2);
            });

            test("the confirmation can be cancelled without charging", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();
                await sales.amountReceived.fill("100");

                await sales.proceedButton.click();
                const confirm = page.getByRole("dialog").filter({ hasText: "Are you sure you would like to proceed?" });
                await confirm.getByRole("button", { name: "Cancel" }).click();

                await expect(confirm).toBeHidden();
                expect((await cartState(cashierApi, cashierId)).items).toHaveLength(1);
            });
        });

        test.describe("card", () => {
            test("choosing Card asks for a card type and lists only active types", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();

                await sales.choosePayment("Card");

                const dialog = page.getByRole("dialog", { name: "Card payment type" });
                await expect(dialog).toBeVisible();
                await expect(dialog.getByText(cards.visa, { exact: true })).toBeVisible();
                await expect(dialog.getByText(cards.inactive, { exact: true })).toHaveCount(0);
                await expect(dialog.getByRole("button", { name: "Use selected type" })).toBeDisabled();
            });

            test("Proceed Payment is disabled while no card type is chosen", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();
                await sales.choosePayment("Card");

                const dialog = page.getByRole("dialog", { name: "Card payment type" });
                await dialog.locator(".ant-modal-close").click();
                await sales.amountReceived.fill("100");

                await expect(sales.proceedButton).toBeDisabled();
            });

            test('"Pay with cash instead" switches back to cash', async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();
                await sales.choosePayment("Card");

                await page.getByRole("dialog", { name: "Card payment type" }).getByRole("button", { name: "Pay with cash instead" }).click();

                await expect(sales.paymentMethod("Cash")).toHaveClass(/ant-radio-button-wrapper-checked/);
            });

            test("pays by card with a selected card type", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();
                await sales.choosePayment("Card");

                const dialog = page.getByRole("dialog", { name: "Card payment type" });
                await dialog.getByText(cards.visa, { exact: true }).click();
                await dialog.getByRole("button", { name: "Use selected type" }).click();
                await expect(dialog).toBeHidden();

                const res = await sales.pay({ amountReceived: 100 });

                expect(res?.status()).toBe(200);
                await expect(sales.notice("Payment Successful!")).toBeVisible();
            });
        });

        test.describe("credit", () => {
            test("Credit is unavailable for walk-in customers", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();

                await expect(sales.paymentMethod("Credit")).toHaveClass(/ant-radio-button-wrapper-disabled/);
            });

            test("Credit is unavailable for a customer without credit", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.selectCustomer("E2E No Credit", customers.noCredit.name);
                await sales.goToCheckout();

                await expect(sales.paymentMethod("Credit")).toHaveClass(/ant-radio-button-wrapper-disabled/);
            });

            test("over the credit limit, payment is blocked", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.setQuantity(products.burger.name, 6); // ₱600 > ₱500 limit
                await sales.selectCustomer("E2E Credit", customers.credit.name);
                await sales.goToCheckout();

                await sales.choosePayment("Credit");

                await expect(page.getByText("Insufficient credit for this transaction")).toBeVisible();
                await expect(sales.proceedButton).toBeDisabled();
            });

            test("within the limit, the sale is charged to the customer's credit", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                const customerId = fixtureIds().customers[customers.credit.name];
                const balanceBefore = Number((await customer(cashierApi, customerId)).credit_balance);
                await sales.addProduct(products.burger.name);
                await sales.selectCustomer("E2E Credit", customers.credit.name);
                await sales.goToCheckout();
                await sales.choosePayment("Credit");

                const res = await sales.pay();

                expect(res?.status()).toBe(200);
                await expect(sales.notice("Credit Sale Processed!")).toBeVisible();
                const balanceAfter = Number((await customer(cashierApi, customerId)).credit_balance);
                expect(balanceAfter).toBeGreaterThanOrEqual(balanceBefore + 100);
            });
        });

        test.describe("customers", () => {
            test("search needs at least 2 characters", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.switchToLoyalCustomer();
                let searched = false;
                page.on("request", (r) => {
                    if (r.url().includes("/api/customers/search")) searched = true;
                });

                await sales.customerSearch.fill("E");
                await page.waitForTimeout(800);

                expect(searched, "no search request for 1 character").toBe(false);
                await expect(page.getByText("Type at least 2 characters to search for existing customers")).toBeVisible();
            });

            test("adding a customer without a name is rejected", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await openAddCustomer(page, layout);

                await page.getByRole("dialog", { name: "Add New Customer" }).getByRole("button", { name: "Add Customer" }).click();

                await expect(sales.notice("Validation Error")).toContainText("Customer name is required");
            });

            test("adding a customer with an email already in use is rejected", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await openAddCustomer(page, layout);
                const dialog = page.getByRole("dialog", { name: "Add New Customer" });
                await dialog.getByPlaceholder("Customer name").fill("E2E Duplicate");
                await dialog.getByPlaceholder("Email address").fill("e2e-loyalty@techiko.test");

                await dialog.getByRole("button", { name: "Add Customer" }).click();

                await expect(sales.notice("Error")).toContainText("email has already been taken");
                await expect(dialog).toBeVisible();
            });

            test("adds a new customer and selects them", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await openAddCustomer(page, layout);
                const dialog = page.getByRole("dialog", { name: "Add New Customer" });
                const name = `E2E New ${layout} ${Date.now()}`;
                await dialog.getByPlaceholder("Customer name").fill(name);
                await dialog.getByPlaceholder("Email address").fill(`e2e-new-${layout}-${Date.now()}@techiko.test`);

                await dialog.getByRole("button", { name: "Add Customer" }).click();

                await expect(sales.notice("Customer Added")).toContainText(name);
                await expect(dialog).toBeHidden();
                await expect(page.getByRole("main").getByText(name).first()).toBeVisible();
            });
        });

        test.describe("loyalty", () => {
            test("a loyalty customer earns points on a cash sale", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.selectCustomer("E2E Loyalty", customers.loyalty.name);
                await sales.goToCheckout();

                const res = await sales.pay({ amountReceived: 100 });

                expect(res?.status()).toBe(200);
                await expect(sales.notice("Payment Successful!")).toContainText("earned");
            });

            test("redeeming points lowers the total", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.increase(products.burger.name); // ₱200
                await sales.selectCustomer("E2E Loyalty", customers.loyalty.name);
                await sales.goToCheckout();

                await page.getByRole("button", { name: "Apply loyalty redemption" }).click();
                const dialog = page.getByRole("dialog", { name: "Loyalty redemption" });
                await dialog.getByRole("spinbutton").fill("1000"); // 100 pts = ₱1 → ₱10
                const patched = page.waitForResponse((r) => r.url().includes("/loyalty-redemption"));
                await dialog.getByRole("button", { name: "Apply" }).click();

                expect((await patched).status()).toBe(200);
                await expect.poll(async () => (await cartState(cashierApi, cashierId)).totals.grand_total).toBe(190);
                await expect(sales.totalText(190)).toBeVisible();
            });

            test("the redemption dialog caps points at the policy maximum", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name); // ₱100 → max 50% = ₱50 = 5000 pts
                await sales.selectCustomer("E2E Loyalty", customers.loyalty.name);
                await sales.goToCheckout();

                await page.getByRole("button", { name: "Apply loyalty redemption" }).click();
                const dialog = page.getByRole("dialog", { name: "Loyalty redemption" });
                await dialog.getByRole("spinbutton").fill("999999");
                await dialog.getByText("Points to redeem").click();

                await expect(dialog.getByRole("spinbutton")).not.toHaveValue("999999");
                await expect(dialog).toContainText("Estimated discount: ₱50.00");
            });
        });

        test.describe("discounts", () => {
            test("an order discount lowers the total and can be cleared", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.increase(products.burger.name); // ₱200
                await sales.goToCheckout();

                await sales.orderDiscountButton().click();
                const dialog = page.getByRole("dialog", { name: "Apply Order Discount" });
                await expect(dialog).toBeVisible();
                await sales.pickSelectOption(dialog, 0, discounts.order);
                await dialog.getByText("Promotional Discounts").click();
                const applied = page.waitForResponse((r) => r.request().method() === "PATCH" && r.url().endsWith("/discounts"));
                await dialog.getByRole("button", { name: "Apply Discount(s)" }).click();

                expect((await applied).status()).toBe(200);
                await expect(sales.notice("Success")).toContainText("Discount(s) applied successfully");
                await expect.poll(async () => (await cartState(cashierApi, cashierId)).totals.grand_total).toBe(180);

                await sales.orderDiscountButton().click();
                const cleared = page.waitForResponse((r) => r.request().method() === "DELETE" && r.url().endsWith("/discounts"));
                await page.getByRole("dialog", { name: "Apply Order Discount" }).getByRole("button", { name: "Clear All Discounts" }).click();

                expect((await cleared).status()).toBe(200);
                await expect.poll(async () => (await cartState(cashierApi, cashierId)).totals.grand_total).toBe(200);
            });

            test("a mandatory discount applies to the order", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.goToCheckout();

                await sales.orderDiscountButton().click();
                const dialog = page.getByRole("dialog", { name: "Apply Order Discount" });
                await sales.pickSelectOption(dialog, 1, discounts.senior);
                const applied = page.waitForResponse((r) => r.request().method() === "PATCH" && r.url().endsWith("/discounts"));
                await dialog.getByRole("button", { name: "Apply Discount(s)" }).click();

                expect((await applied).status()).toBe(200);
                await expect.poll(async () => (await cartState(cashierApi, cashierId)).totals.grand_total).toBe(80);
            });

            test("an item discount applies to one line and can be cleared", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);

                const dialog = await sales.openItemDiscount(products.burger.name);
                await sales.pickSelectOption(dialog, 0, "E2E");
                const applied = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/discounts"));
                // "Clear Discount" only appears once the cart line has reloaded with its discount.
                const refreshed = sales.waitForCartRefresh();
                await dialog.getByRole("button", { name: "Submit" }).click();

                expect((await applied).status()).toBe(200);
                await refreshed;
                await expect(sales.notice("Discount Applied")).toBeVisible();
                await expect.poll(async () => (await cartState(cashierApi, cashierId)).totals.grand_total).toBe(80);

                const again = await sales.openItemDiscount(products.burger.name);
                const cleared = page.waitForResponse((r) => r.request().method() === "DELETE" && r.url().includes("/discounts"));
                await again.getByRole("button", { name: "Clear Discount" }).click();

                expect((await cleared).status()).toBe(200);
                await expect(sales.notice("Discount Cleared")).toBeVisible();
                await expect.poll(async () => (await cartState(cashierApi, cashierId)).totals.grand_total).toBe(100);
            });
        });
    });
}

/** Opens the "Add New Customer" dialog from the Loyal customer panel. */
async function openAddCustomer(page, layout) {
    await new SalesPage(page, layout).switchToLoyalCustomer();
    await page.getByRole("button", { name: "Add New Customer" }).click();
    await expect(page.getByRole("dialog", { name: "Add New Customer" })).toBeVisible();
}
