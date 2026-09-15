import { readFileSync } from "node:fs";
import { expect } from "@playwright/test";
import { apiJson } from "./fixtures.js";
import { E2E } from "./users.js";

/** IDs written by E2ESalesSeeder before the run (tests/e2e/.fixtures.json). */
export function fixtureIds() {
    const path = process.env.E2E_FIXTURES_FILE || "tests/e2e/.fixtures.json";
    return JSON.parse(readFileSync(path, "utf8"));
}

export const salesUrl = (domain = E2E.domain) => `/domains/${domain}/sales`;
export const saleUrl = (saleId, path = "", domain = E2E.domain) => `/domains/${domain}/sales/${saleId}${path}`;
export const userCartUrl = (userId, path = "", domain = E2E.domain) =>
    `/domains/${domain}/users/${userId}/sales${path}`;

const money = (amount) =>
    new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(amount);

/* ------------------------------------------------------------------ */
/* API helpers (serverAs sessions)                                     */
/* ------------------------------------------------------------------ */

/** Latest pending cart of `userId` as the signed-in user sees it. */
export async function cartState(api, userId, domain = E2E.domain) {
    const res = await apiJson(api, "GET", userCartUrl(userId, "/cart/state", domain));
    expect(res.status, "cart state").toBe(200);
    return res.body;
}

export async function addToUserCart(api, userId, productId, quantity = 1, domain = E2E.domain) {
    return apiJson(api, "POST", userCartUrl(userId, "/cart/add", domain), { product_id: productId, quantity });
}

/** Creates (or reuses) a pending cart for the signed-in user with one line, and returns its sale. */
export async function pendingSaleWith(api, userId, productId, quantity = 1, domain = E2E.domain) {
    const res = await addToUserCart(api, userId, productId, quantity, domain);
    expect(res.status, `add product ${productId} to cart: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(200);
    return res.body.sale;
}

/**
 * Empties the signed-in user's pending cart (lines, order discounts, loyalty redemption),
 * so a UI test starts from a known state. Worker cashiers keep one cart across tests.
 */
export async function clearCart(api, userId, domain = E2E.domain) {
    const state = await cartState(api, userId, domain);
    if (!state.sale) return;

    await apiJson(api, "DELETE", saleUrl(state.sale.id, "/discounts", domain));
    await apiJson(api, "PATCH", saleUrl(state.sale.id, "/loyalty-redemption", domain), { loyalty_points: 0 });
    for (const item of state.items) {
        const res = await apiJson(api, "DELETE", userCartUrl(userId, "/cart/remove", domain), { product_id: item.product_id });
        expect(res.status, `remove ${item.product_id} while clearing cart`).toBe(200);
    }
}

/** Signs in as this worker's cashier over HTTP and empties their cart. Returns { api, userId }. */
export async function freshWorkerCart(serverAs, testInfo) {
    const api = await serverAs("worker-cashier");
    const email = `e2e-cashier-${(testInfo.parallelIndex % 4) + 1}@techiko.test`;
    const userId = fixtureIds().users[email];
    await clearCart(api, userId);
    return { api, userId };
}

/** Stock available at the signed-in user's location. */
export async function stockOf(api, productName, domain = E2E.domain) {
    const res = await apiJson(api, "GET", `${salesUrl(domain)}/products?search=${encodeURIComponent(productName)}&per_page=50`);
    expect(res.status, "products list").toBe(200);
    const product = res.body.data.find((p) => p.name === productName);
    expect(product, `product ${productName} listed`).toBeTruthy();
    return product.location_quantity_available;
}

export async function customer(api, id) {
    const res = await apiJson(api, "GET", `/api/customers/${id}`);
    expect(res.status, "customer details").toBe(200);
    return res.body;
}

/* ------------------------------------------------------------------ */
/* UI page object                                                      */
/* ------------------------------------------------------------------ */

/**
 * Sales page in the Classic or Modern (coffeeshop) layout. Cart and payment calls are
 * awaited on their network responses, so assertions run against the settled cart.
 */
export class SalesPage {
    constructor(page, layout) {
        this.page = page;
        this.layout = layout;
        this.isModern = layout === "coffeeshop";
    }

    /** Sets the layout preference before the app loads, then opens the Sales page. */
    static async open(page, { layout = "classic" } = {}) {
        await page.addInitScript(
            ([key, value]) => localStorage.setItem(key, value),
            [`sales_layout_mode_${E2E.domain}`, layout],
        );
        const sales = new SalesPage(page, layout);
        await Promise.all([sales.waitForProducts(), page.goto(salesUrl())]);
        await expect(sales.searchBox).toBeVisible();
        return sales;
    }

    get searchBox() {
        return this.page.getByPlaceholder(this.isModern ? "Search menu..." : "Search Product");
    }

    waitForProducts() {
        return this.page.waitForResponse((r) => r.url().includes("/sales/products") && r.request().method() === "GET");
    }

    waitForCart(method, pathPart) {
        return this.page.waitForResponse(
            (r) => r.request().method() === method && new URL(r.url()).pathname.includes(pathPart),
        );
    }

    /** Waits for the page to reload the pending cart after a change. */
    waitForCartRefresh() {
        return this.page.waitForResponse(
            (r) => r.request().method() === "GET" && /\/users\/\d+\/sales\/(current-pending|cart\/state)/.test(r.url()),
        );
    }

    async search(text) {
        // Re-entering the same text doesn't trigger a new request.
        if ((await this.searchBox.inputValue()) === text) return;
        const loaded = this.waitForProducts();
        await this.searchBox.fill(text);
        await loaded;
    }

    /** Product tile (Modern) or card (Classic) in the catalog. */
    productTile(name) {
        if (this.isModern) {
            return this.page.getByRole("main").getByRole("button", { name: new RegExp(`${escapeRegex(name)} ₱`) });
        }
        return this.page
            .getByRole("main")
            .locator("div.border")
            .filter({ has: this.page.getByText(name, { exact: true }) })
            .filter({ has: this.page.getByRole("button", { name: /Add to Cart|Out of stock/ }) });
    }

    addButton(name) {
        return this.isModern ? this.productTile(name) : this.productTile(name).getByRole("button");
    }

    /** Adds one unit via the catalog and returns the add-to-cart response. */
    async addProduct(name) {
        await this.search(name);
        const added = this.waitForCart("POST", "/cart/add");
        // Not awaited when the add fails; swallow its rejection when the page closes.
        const refreshed = this.waitForCartRefresh().catch(() => null);
        await this.addButton(name).click();
        const response = await added;
        if (response.ok()) {
            await refreshed;
            await expect(this.quantityButton(name)).toBeVisible();
        }
        return response;
    }

    /** The whole cart line; clicking it opens the item discount dialog. */
    cartRow(name) {
        return this.quantityButton(name).locator("xpath=ancestor::div[contains(@class,'justify-between')][1]");
    }

    /** Opens "Apply Discount - <name>" by clicking the line's product name. */
    async openItemDiscount(name) {
        await this.cartRow(name).getByText(name, { exact: true }).click();
        const dialog = this.page.getByRole("dialog", { name: `Apply Discount - ${name}` });
        await expect(dialog).toBeVisible();
        return dialog;
    }

    /** Picks an option in an antd select inside `scope` (dialog) whose label contains `optionText`. */
    async pickSelectOption(scope, selectIndex, optionText) {
        const select = scope.locator(".ant-select").nth(selectIndex);
        await select.click();
        const dropdown = this.page.locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden)").last();
        await expect(dropdown).toBeVisible();
        // The virtual list re-renders while the dropdown animates open; clicking too early hits a detached node.
        await this.page.waitForTimeout(300);

        // Options are virtualized, so far-down ones aren't in the DOM until scrolled to.
        // (Typing doesn't help: these selects filter on option IDs, not labels.)
        const option = dropdown.locator(`.ant-select-item-option[title*="${optionText.replaceAll('"', '\\"')}"]`).first();
        const holder = dropdown.locator(".rc-virtual-list-holder");
        for (let i = 0; i < 30 && !(await option.isVisible()); i++) {
            await holder.evaluate((el) => (el.scrollTop += 120));
            await this.page.waitForTimeout(80);
        }
        await option.click();
    }

    /** Customer search box (antd auto-complete; its placeholder isn't an input attribute). */
    get customerSearch() {
        return this.page.locator(".ant-select-auto-complete").getByRole("combobox");
    }

    async switchToLoyalCustomer() {
        if (this.isModern) {
            await this.page.getByRole("button", { name: "Loyal", exact: true }).click();
        } else {
            await this.page.getByRole("switch", { name: "Walk-in" }).click();
        }
        await expect(this.customerSearch).toBeVisible();
    }

    quantityButton(name) {
        return this.page.getByRole("button", { name: `Edit ${name} quantity` });
    }

    async quantityOf(name) {
        return Number((await this.quantityButton(name).innerText()).trim());
    }

    async increase(name) {
        const done = this.waitForCart("POST", "/cart/add");
        await this.page.getByRole("button", { name: `Increase ${name}` }).click();
        return done;
    }

    async decrease(name) {
        const done = this.waitForCart("PATCH", "/cart/update-quantity");
        await this.page.getByRole("button", { name: `Decrease ${name}` }).click();
        return done;
    }

    /** Uses the "Update Quantity" dialog. Returns the PATCH response, or null if none was sent. */
    async setQuantity(name, quantity) {
        await this.quantityButton(name).click();
        const dialog = this.page.getByRole("dialog", { name: "Update Quantity" });
        await expect(dialog).toBeVisible();
        await dialog.getByRole("spinbutton").fill(String(quantity));

        const patch = this.waitForCart("PATCH", "/cart/update-quantity").catch(() => null);
        await dialog.getByRole("button", { name: "Update Quantity" }).click();
        return Promise.race([patch, this.page.waitForTimeout(3000).then(() => null)]);
    }

    /** Opens the "Void Product" dialog for a line and submits the PIN. Returns the void response. */
    async voidLine(name, pin, reason = "E2E void") {
        await this.page.getByRole("button", { name: `Remove ${name}` }).click();
        const dialog = this.page.getByRole("dialog", { name: "Void Product" });
        await expect(dialog).toBeVisible();
        await dialog.locator('input[type="password"]').fill(pin);
        await dialog.locator("textarea").fill(reason);

        const voided = this.waitForCart("POST", "/sales-items/void");
        await dialog.getByRole("button", { name: "Submit" }).click();
        return { response: await voided, dialog };
    }

    /** Modern layout keeps payment behind "Charge"; Classic shows it in the footer. */
    async goToCheckout() {
        if (this.isModern) {
            await this.page.getByRole("button", { name: "Continue to checkout" }).click();
            await expect(this.page.getByText("Checkout", { exact: true })).toBeVisible();
            await expect(this.proceedButton).toBeInViewport();
            // The payment panel slides in over 300ms; clicks during the slide miss their target.
            await this.page.waitForTimeout(400);
        }
    }

    paymentMethod(label) {
        return this.page.locator(".ant-radio-button-wrapper").filter({ hasText: label });
    }

    async choosePayment(label) {
        await expect(async () => {
            await this.paymentMethod(label).click();
            await expect(this.paymentMethod(label)).toHaveClass(/ant-radio-button-wrapper-checked/, { timeout: 1000 });
        }).toPass({ timeout: 10_000 });
    }

    get amountReceived() {
        return this.page.locator('input[type="number"][placeholder="0"]').last();
    }

    get proceedButton() {
        return this.page.getByRole("button", { name: "Proceed Payment" });
    }

    /** Clicks Proceed Payment and confirms. Returns the payment response. */
    async pay({ amountReceived } = {}) {
        if (amountReceived !== undefined) {
            await this.amountReceived.fill(String(amountReceived));
        }
        await this.proceedButton.click();
        const confirm = this.page.getByRole("dialog").filter({ hasText: "Are you sure you would like to proceed?" });
        await expect(confirm).toBeVisible();

        const paid = this.page
            .waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/payments"), { timeout: 15_000 })
            .catch(() => null);
        await confirm.getByRole("button", { name: "Submit" }).click();
        return paid;
    }

    /** Switches to the "Loyal" customer mode and selects a customer from search. */
    async selectCustomer(query, name) {
        await this.switchToLoyalCustomer();
        const searched = this.page.waitForResponse((r) => r.url().includes("/api/customers/search"));
        await this.customerSearch.fill(query);
        await searched;
        const dropdown = this.page.locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden)").last();
        await expect(dropdown).toBeVisible();
        await this.page.waitForTimeout(300);
        await dropdown.locator(".ant-select-item-option").filter({ hasText: name }).first().click();
        await expect(this.notice("Customer Selected")).toBeVisible();
    }

    orderDiscountButton() {
        return this.page.getByRole("button", { name: "Apply Order Discount" });
    }

    /** antd notification by title. */
    notice(title) {
        return this.page.locator(".ant-notification-notice").filter({ hasText: title });
    }

    /** Grand total text shown to the cashier, e.g. "₱200.00". */
    totalText(amount) {
        return this.page.getByText(money(amount), { exact: true }).first();
    }
}

export function escapeRegex(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

export { money };
