import { test, expect } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage, freshWorkerCart } from "../support/sales.js";

const { products, pins } = E2E;

for (const layout of ["classic", "coffeeshop"]) {
    test.describe(`Cart (${layout} layout)`, () => {
        test.use({ account: "worker-cashier" });

        test.beforeEach(async ({ serverAs }, testInfo) => {
            await freshWorkerCart(serverAs, testInfo);
        });

        test("adding a product creates a cart line and updates the total", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });

            const res = await sales.addProduct(products.burger.name);

            expect(res.status()).toBe(200);
            expect(await sales.quantityOf(products.burger.name)).toBe(1);
            await expect(sales.totalText(100)).toBeVisible();
        });

        test("adding the same product again increments its quantity", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });

            await sales.addProduct(products.fries.name);
            await sales.addProduct(products.fries.name);

            await expect(sales.quantityButton(products.fries.name)).toHaveText("2");
            await expect(sales.totalText(100)).toBeVisible();
        });

        test("plus and minus buttons change the quantity", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.burger.name);

            expect((await sales.increase(products.burger.name)).status()).toBe(200);
            await expect(sales.quantityButton(products.burger.name)).toHaveText("2");

            expect((await sales.decrease(products.burger.name)).status()).toBe(200);
            await expect(sales.quantityButton(products.burger.name)).toHaveText("1");
        });

        test("minus at quantity 1 doesn't leave the cart in a broken state", async ({ page, observe }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.burger.name);

            const res = await sales.decrease(products.burger.name);

            // Either the line is removed, or the quantity stays at 1 with feedback. It must not show 0.
            if (res.status() !== 200) {
                observe(`Minus at quantity 1 sends quantity 0 and the server rejects it (${res.status()}) with no message to the cashier.`);
            }
            await page.waitForTimeout(500);
            const line = sales.quantityButton(products.burger.name);
            if (await line.count()) {
                await expect(line, "a cart line must never show quantity 0").not.toHaveText("0");
            }
        });

        test("the quantity dialog sets an exact quantity", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.burger.name);

            const res = await sales.setQuantity(products.burger.name, 3);

            expect(res?.status()).toBe(200);
            await expect(sales.notice("Quantity updated")).toBeVisible();
            await expect(sales.quantityButton(products.burger.name)).toHaveText("3");
            await expect(sales.totalText(300)).toBeVisible();
        });

        test("the quantity dialog rejects 0", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.burger.name);

            await sales.setQuantity(products.burger.name, 0);

            await expect(
                page.locator(".ant-notification-notice-error, .ant-notification-notice").filter({ hasText: /Invalid quantity|Update failed/ }),
                "setting 0 must tell the cashier it failed",
            ).toBeVisible();
            await expect(sales.quantityButton(products.burger.name)).toHaveText("1");
        });

        test("can't add more than the stock on hand", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.limited.name);
            await sales.addProduct(products.limited.name);

            const third = await sales.increase(products.limited.name);

            expect(third.status()).toBe(422);
            await expect(sales.notice("Insufficient stock")).toBeVisible();
            await expect(sales.notice("Insufficient stock")).toContainText(products.limited.name);
            await expect(sales.quantityButton(products.limited.name)).toHaveText("2");
        });

        test("the quantity dialog can't exceed the stock on hand", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.limited.name);

            const res = await sales.setQuantity(products.limited.name, 3);

            expect(res?.status()).toBe(422);
            await expect(sales.notice("Insufficient stock").first()).toBeVisible();
            await page.waitForTimeout(300);
            // Counted once (not retried): notifications fade out, which would hide a duplicate.
            expect(await sales.notice("Insufficient stock").count(), "the error should be shown once, not duplicated").toBe(1);
            await expect(sales.quantityButton(products.limited.name)).toHaveText("1");
        });

        test("the cart survives a page reload", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.addProduct(products.fries.name);
            await sales.increase(products.fries.name);

            await page.reload();

            await expect(sales.quantityButton(products.fries.name)).toHaveText("2");
        });

        test.describe("voiding a line", () => {
            test("an empty PIN is rejected", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);

                const { response, dialog } = await sales.voidLine(products.burger.name, "");

                expect(response.status()).toBe(422);
                await expect(dialog).toBeVisible();
                await expect(dialog.locator(".ant-form-item-explain-error")).not.toHaveText("");
                await expect(sales.quantityButton(products.burger.name)).toBeVisible();
            });

            test("a wrong PIN is rejected", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);

                const { response, dialog } = await sales.voidLine(products.burger.name, "0000");

                expect(response.status()).toBe(422);
                await expect(dialog).toContainText("The provided Pin Code is incorrect.");
                await expect(sales.quantityButton(products.burger.name)).toBeVisible();
            });

            test("a cashier's own password is not accepted as a PIN", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);

                const { response } = await sales.voidLine(products.burger.name, "e2e-password");

                expect(response.status()).toBe(422);
            });

            test("a manager PIN removes the line and recalculates the total", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);
                await sales.addProduct(products.fries.name);
                await expect(sales.totalText(150)).toBeVisible();

                const { response, dialog } = await sales.voidLine(products.burger.name, pins.manager, "Customer changed mind");

                expect(response.status()).toBe(200);
                await expect(dialog).toBeHidden();
                await expect(sales.notice("Success")).toContainText("successfully voided");
                await expect(sales.quantityButton(products.burger.name)).toHaveCount(0);
                await expect(sales.quantityButton(products.fries.name)).toBeVisible();
                await expect(sales.totalText(50)).toBeVisible();
            });

            test("an admin PIN is also accepted", async ({ page }) => {
                const sales = await SalesPage.open(page, { layout });
                await sales.addProduct(products.burger.name);

                const { response } = await sales.voidLine(products.burger.name, pins.admin);

                expect(response.status()).toBe(200);
                await expect(sales.quantityButton(products.burger.name)).toHaveCount(0);
            });
        });
    });
}
