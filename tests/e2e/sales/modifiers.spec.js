import { test, expect, apiJson } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage, addToUserCart, cartState, freshWorkerCart } from "../support/sales.js";

/**
 * Product modifiers (add-ons): a product with option groups asks for them before it goes into the
 * cart, is priced with them, and the same product with other options is its own cart line.
 *
 * E2E Latte (₱100) and its required "E2E Size" group (Regular, or Large +₱20) come from
 * E2ESalesSeeder and are used by nothing else, so the picker never pops up in other suites.
 */

const { products } = E2E;
const modifiersPath = `/domains/${E2E.domain}/modifier-groups`;

test.describe("Modifiers at the POS (classic layout)", () => {
    test.use({ account: "worker-cashier" });

    let cashierApi;
    let cashierId;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        ({ api: cashierApi, userId: cashierId } = await freshWorkerCart(serverAs, testInfo));
    });

    async function pickAndAdd(page, sales, { size, note } = {}) {
        await sales.search(products.latte.name);
        await sales.addButton(products.latte.name).click();

        const picker = page.getByRole("dialog").filter({ has: page.getByTestId("modifier-picker") });
        await expect(picker).toBeVisible();
        if (size) {
            await picker.getByRole("radio", { name: new RegExp(`^${size}`) }).check();
        }
        if (note) {
            await picker.getByRole("textbox", { name: "Note for this item" }).fill(note);
        }

        const added = page.waitForRequest((r) => r.method() === "POST" && r.url().includes("/cart/add"));
        const refreshed = sales.waitForCartRefresh();
        await picker.getByRole("button", { name: "Add to order" }).click();
        const body = (await added).postDataJSON();
        await refreshed;
        await expect(picker).toBeHidden();

        return body;
    }

    test("asks for the size, prices the line with it and shows it on the cart", async ({ page }) => {
        const sales = await SalesPage.open(page, { layout: "classic" });

        const picker = page.getByRole("dialog").filter({ has: page.getByTestId("modifier-picker") });
        await sales.search(products.latte.name);
        await sales.addButton(products.latte.name).click();
        await expect(picker).toBeVisible();
        await expect(picker.getByText("Required")).toBeVisible();
        // A required single choice starts on its first option.
        await expect(picker.getByRole("radio", { name: /^Regular/ })).toBeChecked();
        await picker.getByRole("radio", { name: /^Large/ }).check();
        await expect(picker).toContainText("₱120.00");
        await page.keyboard.press("Escape");

        const body = await pickAndAdd(page, sales, { size: "Large", note: "less ice" });
        expect(body.modifier_ids).toHaveLength(1);
        expect(body.notes).toBe("less ice");

        await expect(page.getByTestId("cart-line-options")).toContainText("Large · less ice");
        const state = await cartState(cashierApi, cashierId);
        const line = state.items.find((i) => i.product_id && i.modifiers?.length);
        expect(Number(line.unit_price), "₱100 + ₱20 for Large").toBe(120);
    });

    test("the same product with another size is its own line, and changes stay on their line", async ({ page }) => {
        const sales = await SalesPage.open(page, { layout: "classic" });

        await pickAndAdd(page, sales, { size: "Large" });
        await pickAndAdd(page, sales, { size: "Regular" });
        await pickAndAdd(page, sales, { size: "Large" });

        const lines = (await cartState(cashierApi, cashierId)).items;
        expect(lines, "one line per size").toHaveLength(2);
        const large = lines.find((l) => Number(l.unit_price) === 120);
        const regular = lines.find((l) => Number(l.unit_price) === 100);
        expect(Number(large.quantity)).toBe(2);
        expect(Number(regular.quantity)).toBe(1);

        // Increase on the Regular line goes onto that line, not onto Large.
        const increaseButtons = page.getByRole("button", { name: `Increase ${products.latte.name}` });
        await expect(increaseButtons).toHaveCount(2);
        const options = page.getByTestId("cart-line-options");
        const regularIndex = (await options.allInnerTexts()).findIndex((t) => t.includes("Regular"));
        const added = sales.waitForCart("POST", "/cart/add");
        const refreshed = sales.waitForCartRefresh();
        await increaseButtons.nth(regularIndex).click();
        await added;
        await refreshed;

        await expect
            .poll(async () => {
                const now = (await cartState(cashierApi, cashierId)).items;
                return now.map((l) => `${Number(l.unit_price)}x${Number(l.quantity)}`).sort();
            })
            .toEqual(["100x2", "120x2"]);
    });

    test("the server refuses a latte without its size", async () => {
        const res = await apiJson(cashierApi, "GET", `/domains/${E2E.domain}/sales/products?search=${encodeURIComponent(products.latte.name)}`);
        const latte = res.body.data.find((p) => p.name === products.latte.name);
        expect(latte.modifier_groups[0].name).toBe("E2E Size");

        const add = await addToUserCart(cashierApi, cashierId, latte.id);
        expect(add.status).toBe(422);
        expect(Object.keys(add.body.errors ?? {})).toContain("modifier_ids");
    });
});

test.describe("Managing modifiers (admin)", () => {
    test.use({ account: "admin" });

    test("a group is created with its options and deleted", async ({ page }, testInfo) => {
        const name = `E2E New Sugar ${testInfo.parallelIndex}-${Date.now() % 100000}`;

        await page.goto(modifiersPath);
        await expect(page.getByRole("cell", { name: "E2E Size" })).toBeVisible();

        await page.getByRole("button", { name: "Create Modifier Group" }).click();
        const dialog = page.getByRole("dialog", { name: "Create Modifier Group" });
        await dialog.getByPlaceholder("e.g. Size, Sugar level, Add-ons").fill(name);
        const optionNames = dialog.getByPlaceholder("e.g. Large");
        await optionNames.nth(0).fill("Less sweet");
        await optionNames.nth(1).fill("Extra sweet");

        const saved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/modifier-groups"));
        await dialog.getByRole("button", { name: "Save" }).click();
        await saved;
        await expect(dialog).toBeHidden();

        const row = page.locator(".ant-table-row").filter({ hasText: name });
        await expect(row).toContainText("Less sweet, Extra sweet");
        await expect(row).toContainText("One · optional");

        await row.getByRole("button", { name: "Delete" }).click();
        const confirm = page.getByRole("dialog").filter({ hasText: `Delete “${name}”?` });
        await confirm.getByRole("button", { name: "Delete" }).click();
        await expect(row).toHaveCount(0);
    });

    test("an option needs a name", async ({ page }) => {
        await page.goto(modifiersPath);
        await page.getByRole("button", { name: "Create Modifier Group" }).click();
        const dialog = page.getByRole("dialog", { name: "Create Modifier Group" });
        await dialog.getByPlaceholder("e.g. Size, Sugar level, Add-ons").fill("E2E New Unnamed");

        await dialog.getByRole("button", { name: "Save" }).click();

        await expect(dialog.getByText("The modifiers.0.name field is required.").or(dialog.getByText(/name field is required/i)).first()).toBeVisible();
        await page.keyboard.press("Escape");
    });
});
