import { expect } from "@playwright/test";
import { pickSelectOption } from "./antd.js";
import { fixtureIds } from "./sales.js";
import { workerNumber } from "./users.js";

export const PRODUCTS_DOMAIN = "jollibee-corp";
export const productsUrl = (path = "") => `/domains/${PRODUCTS_DOMAIN}/products${path}`;
export const productUrl = (id, path = "") => productsUrl(`/${id}${path}`);

/** This worker's seeded store catalog (E2EProductSeeder writes it under `catalog`). */
export function productFixture(testInfo) {
    return fixtureIds().catalog.workers[workerNumber(testInfo.parallelIndex)];
}

export const productsFixtures = () => fixtureIds().catalog;

/**
 * Unique values for products a test creates. The seeder deletes "E2E New …" products and
 * "E2E-NEW-" SKUs/barcodes before each run. Always pass a barcode unless a test is about
 * blank barcodes: (domain, barcode) is unique and a blank barcode is stored as ''.
 */
export function newProductValues(label) {
    const id = `${Date.now().toString(36)}${Math.floor(Math.random() * 10_000)}`;
    return {
        name: `E2E New ${label} ${id}`,
        sku: `E2E-NEW-${id}`.toUpperCase(),
        barcode: `E2E-NEW-BAR-${id}`.toUpperCase(),
    };
}

export class ProductsPage {
    constructor(page) {
        this.page = page;
    }

    static async open(page, query = "") {
        await page.goto(`${productsUrl()}${query}`);
        await expect(page.locator(".ant-table")).toBeVisible();
        return new ProductsPage(page);
    }

    get rows() {
        return this.page.locator(".ant-table-tbody tr.ant-table-row");
    }

    row(name) {
        return this.rows.filter({ has: this.page.getByText(name, { exact: true }) });
    }

    waitForList(predicate = () => true) {
        return this.page.waitForResponse((r) => {
            const url = new URL(r.url());
            return r.request().method() === "GET" && url.pathname === productsUrl() && predicate(url.searchParams);
        });
    }

    async search(text) {
        const loaded = this.waitForList((q) => (q.get("search") ?? "") === text);
        await this.page.getByPlaceholder("Search products").fill(text);
        await loaded;
    }

    /** Sets a filter from the filter popover (e.g. "Category", "Sold Type"). */
    async filter(label, option) {
        await this.page.getByRole("button", { name: "filter" }).click();
        const item = this.page.locator(".ant-popover:not(.ant-popover-hidden) .ant-form-item").filter({ hasText: label });
        await expect(item).toBeVisible();
        const loaded = this.waitForList();
        await pickSelectOption(this.page, item, 0, option);
        await loaded;
        await this.page.keyboard.press("Escape");
    }

    async goToPage(n) {
        const loaded = this.waitForList((q) => q.get("page") === String(n));
        await this.page.locator(`.ant-pagination-item-${n}`).click();
        await loaded;
    }
}

/** Create / Edit product form. */
export class ProductForm {
    constructor(page) {
        this.page = page;
    }

    field(label) {
        return this.page.locator(".ant-form-item").filter({ has: this.page.locator(`label:text-is("${label}")`) });
    }

    error(label) {
        return this.field(label).locator(".ant-form-item-explain-error");
    }

    async fill({ name, category, cost, price, sku, barcode, soldType, color } = {}) {
        if (name !== undefined) await this.page.getByPlaceholder("Enter product name").fill(name);
        if (category) await pickSelectOption(this.page, this.field("Category (optional)"), 0, category);
        if (cost !== undefined) await this.page.getByPlaceholder("Enter cost").fill(String(cost));
        if (price !== undefined) await this.page.getByPlaceholder("Enter price").fill(String(price));
        if (sku !== undefined) await this.page.getByPlaceholder("Enter SKU").fill(sku);
        if (barcode !== undefined) {
            // Typing a barcode fires a debounced shared-catalog lookup. If it overlaps the save,
            // the file-session race can drop the flashed validation errors, so let it finish first.
            const lookedUp = barcode
                ? this.page.waitForResponse((r) => r.url().includes("/shared-catalog/lookup"), { timeout: 5000 }).catch(() => null)
                : null;
            await this.page.getByPlaceholder("Enter barcode").fill(barcode);
            await lookedUp;
        }
        if (soldType) await this.page.locator(".ant-radio-wrapper").filter({ hasText: new RegExp(`^\\s*${soldType}\\s*$`) }).click();
        if (color !== undefined) await this.page.getByPlaceholder("e.g. ff5733 (no #)").fill(color);
    }

    /**
     * Clicks the submit button and returns the POST response (a redirect on success and on
     * validation errors). Returns right away so short-lived toasts can still be checked; field
     * errors render once Inertia follows the redirect, so assert them with a generous timeout.
     */
    async submit(buttonName) {
        const posted = this.page.waitForResponse(
            (r) => r.request().method() === "POST" && new URL(r.url()).pathname.startsWith(productsUrl()),
        );
        await this.page.getByRole("button", { name: buttonName }).last().click();
        return posted;
    }
}
