import { test, expect } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage, cartState, freshWorkerCart } from "../support/sales.js";

/**
 * Selling by weight: a product sold by the kilo asks "How much?" before it goes into the cart,
 * the line keeps the decimals and shows the unit, and + / − move it a quarter kilo at a time.
 *
 * E2E Rice (₱50 per kg, sold type Kilo) comes from E2ESalesSeeder and is used by nothing else.
 */

const { products } = E2E;

test.describe("Weighed products at the POS (classic layout)", () => {
    test.use({ account: "worker-cashier" });

    let cashierApi;
    let cashierId;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        ({ api: cashierApi, userId: cashierId } = await freshWorkerCart(serverAs, testInfo));
    });

    test("asks how much, adds 1.5 kg at ₱75 and steps by a quarter kilo", async ({ page }) => {
        const sales = await SalesPage.open(page, { layout: "classic" });

        await sales.search(products.rice.name);
        await sales.addButton(products.rice.name).click();

        const prompt = page.getByRole("dialog").filter({ has: page.getByTestId("quantity-prompt") });
        await expect(prompt).toBeVisible();
        await expect(prompt.getByTestId("quantity-prompt-add")).toBeDisabled();

        await prompt.getByTestId("quantity-prompt-amount").fill("1.5");
        await expect(prompt.getByTestId("quantity-prompt-price")).toHaveText("₱75.00");

        const added = page.waitForRequest((r) => r.method() === "POST" && r.url().includes("/cart/add"));
        const refreshed = sales.waitForCartRefresh();
        await prompt.getByTestId("quantity-prompt-add").click();
        expect((await added).postDataJSON().quantity).toBe(1.5);
        await refreshed;
        await expect(prompt).toBeHidden();

        await expect(page.getByRole("button", { name: `Edit ${products.rice.name} quantity` })).toContainText("1.5 kg");
        let line = (await cartState(cashierApi, cashierId)).items.find((i) => Number(i.quantity) > 0);
        expect(Number(line.quantity)).toBe(1.5);

        const stepped = sales.waitForCartRefresh();
        await page.getByRole("button", { name: `Increase ${products.rice.name}` }).click();
        await stepped;
        await expect(page.getByRole("button", { name: `Edit ${products.rice.name} quantity` })).toContainText("1.75 kg");
        line = (await cartState(cashierApi, cashierId)).items.find((i) => Number(i.quantity) > 0);
        expect(Number(line.quantity)).toBe(1.75);
    });

    test("a quick amount fills the prompt, and cancelling adds nothing", async ({ page }) => {
        const sales = await SalesPage.open(page, { layout: "classic" });

        await sales.search(products.rice.name);
        await sales.addButton(products.rice.name).click();
        const prompt = page.getByRole("dialog").filter({ has: page.getByTestId("quantity-prompt") });
        await prompt.getByRole("button", { name: "0.5 kg" }).click();
        await expect(prompt.getByTestId("quantity-prompt-price")).toHaveText("₱25.00");

        await page.keyboard.press("Escape");
        await expect(prompt).toBeHidden();
        expect((await cartState(cashierApi, cashierId)).items).toHaveLength(0);
    });
});
