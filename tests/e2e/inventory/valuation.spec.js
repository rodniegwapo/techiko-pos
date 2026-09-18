import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The inventory valuation report (Inventory > Valuation).
 *
 * The E2E Inventory Store from E2EInventorySeeder has known stock, so the report's totals, rows
 * and ordering have exact expected values: 50 units at ₱10, 3 at ₱20 and 8 at ₱15, plus one
 * product with no stock at all, which a valuation report leaves out.
 *
 * Not covered: the Export button, which is still a stub that only logs to the console, and Print,
 * which hands off to the browser's own print dialog.
 */

const fixture = () => fixtureIds().inventoryDashboard;
const valuationPath = "/domains/jollibee-corp/inventory/valuation";
const storeUrl = (locationId = fixture().store.id) => `${valuationPath}?location_id=${locationId}`;

/** The seeded products that still have stock, which are the ones a valuation report shows. */
const inStock = () => Object.values(fixture().products).filter((p) => p.qty > 0);
const unitsInStock = () => inStock().reduce((total, p) => total + p.qty, 0);

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const cell = (row, name) =>
    row.locator("td").nth(["product", "sku", "quantity", "cost", "value", "movement"].indexOf(name));

/** One of the three totals above the table, e.g. card(page, "Total Quantity"). */
const card = (page, label) => page.locator("div.bg-gray-50.rounded-lg.p-6").filter({ hasText: label });

async function openValuation(page, url = storeUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

/** The named column's text, row by row, in the order the table shows them. */
async function column(page, name) {
    const texts = [];
    for (const row of await rows(page).all()) {
        texts.push((await cell(row, name).innerText()).trim());
    }
    return texts;
}

const peso = (amount) => `₱${amount.toLocaleString("en-US", { minimumFractionDigits: 2 })}`;

test.describe("Inventory valuation report (admin)", () => {
    test.use({ account: "admin" });

    test("totals the value, the units and the products holding stock", async ({ page }) => {
        await openValuation(page);

        await expect(card(page, "Total Inventory Value")).toContainText(peso(fixture().summary.value));
        await expect(card(page, "Total Quantity")).toContainText(String(unitsInStock()));
        await expect(card(page, "Total Products")).toContainText(String(inStock().length));
    });

    test("lists a row per product with stock, with its cost and value", async ({ page }) => {
        const { plenty } = fixture().products;
        await openValuation(page);

        await expect(rows(page)).toHaveCount(inStock().length);
        const row = rowWith(page, plenty.sku);
        await expect(cell(row, "product")).toHaveText(plenty.name);
        await expect(cell(row, "sku")).toHaveText(plenty.sku);
        await expect(cell(row, "quantity")).toHaveText(String(plenty.qty));
        await expect(cell(row, "cost")).toHaveText(peso(10));
        await expect(cell(row, "value")).toContainText(peso(plenty.qty * 10));
    });

    test("a product with no stock left is not in the report", async ({ page }) => {
        const { empty } = fixture().products;
        await openValuation(page);

        await expect(rowWith(page, empty.sku)).toHaveCount(0);
        await expect(page.getByText(empty.name, { exact: true })).toHaveCount(0);
    });

    test("stock that never moved shows no movement date", async ({ page }) => {
        await openValuation(page);

        await expect(cell(rowWith(page, fixture().products.plenty.sku), "movement")).toHaveText("N/A");
    });

    test("the report summary repeats the totals and the grand total", async ({ page }) => {
        await openValuation(page);

        const summary = page.locator("div.mb-6.px-6").filter({ hasText: "Report Summary" });
        await expect(summary).toContainText(
            `Total value of ${inStock().length} products with ${unitsInStock()} units in stock`,
        );
        await expect(summary).toContainText(peso(fixture().summary.value));
    });

    test("the store the report is for is named on the page", async ({ page }) => {
        await openValuation(page);

        await expect(page.locator(".ant-alert")).toContainText(fixture().store.name);
        await expect(page.locator(".ant-card-head")).toContainText("Inventory Valuation Details");
    });

    test("sorting by total value puts the smallest first, then the largest", async ({ page }) => {
        await openValuation(page);
        const header = page.locator(".ant-table-thead th").filter({ hasText: "Total Value" });

        await header.click();
        expect(await column(page, "value")).toEqual([peso(60), peso(120), peso(500)]);

        await header.click();
        expect(await column(page, "value")).toEqual([peso(500), peso(120), peso(60)]);
    });

    test("sorting by quantity orders the rows by units on hand", async ({ page }) => {
        await openValuation(page);

        await page.locator(".ant-table-thead th").filter({ hasText: "Quantity" }).click();

        expect(await column(page, "quantity")).toEqual(
            inStock()
                .map((p) => String(p.qty))
                .sort((a, b) => a - b),
        );
    });

    test("picking another store reports on that store", async ({ page }) => {
        const { branchLocation } = fixture();
        await openValuation(page);

        await page.locator("button:has(.anticon-filter)").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
        const loaded = page.waitForResponse(
            (r) => new URL(r.url()).searchParams.get("location_id") === String(branchLocation.id),
        );
        await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Location" }), 0, branchLocation.name);
        await loaded;

        await expect(page.locator(".ant-alert")).toContainText(branchLocation.name);
        await expect(page).toHaveURL((url) => url.searchParams.get("location_id") === String(branchLocation.id));
        // The E2E store's stock is only at that store, so none of it is in the branch's report.
        await expect(rowWith(page, fixture().products.plenty.sku)).toHaveCount(0);
    });

    test("the store in the URL shows up as the report's active filter", async ({ page }) => {
        await openValuation(page, storeUrl(fixture().mainLocation.id));

        await expect(page.locator(".ant-tag").filter({ hasText: "Location" })).toContainText(
            fixture().mainLocation.name,
        );
    });

    test("opening the report asks the server for it once", async ({ page }) => {
        const asked = [];
        page.on("request", (request) => {
            if (new URL(request.url()).pathname === valuationPath) {
                asked.push(request.url());
            }
        });

        await openValuation(page, storeUrl(fixture().mainLocation.id));
        await page.waitForTimeout(2000);

        expect(asked, "the report is not re-requested after it loads").toHaveLength(1);
    });

    test("a store in another organization reports nothing", async ({ page }) => {
        await openValuation(page, storeUrl(fixture().otherOrgLocationId));

        await expect(card(page, "Total Inventory Value")).toContainText(peso(0));
        await expect(card(page, "Total Quantity")).toContainText("0");
        await expect(rows(page)).toHaveCount(0);
        expect((await pageProps(page)).location).toBeNull();
    });
});

test.describe("Inventory valuation as a manager", () => {
    test.use({ account: "manager" });

    test("reports on their own store, even with another store in the URL", async ({ page }) => {
        await openValuation(page);

        const { location, items } = await pageProps(page);
        expect(location.code).toBe(fixture().mainLocation.code);
        expect(items.length, "their store's stock").toBeGreaterThan(0);
    });
});

test.describe("Inventory valuation access (API)", () => {
    test("the totals add up to the rows the report lists", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { summary, items } = await responseProps(await api.get(storeUrl()));

        expect(items).toHaveLength(inStock().length);
        expect(Number(summary.total_value)).toBe(items.reduce((total, i) => total + Number(i.total_value), 0));
        expect(summary.total_quantity).toBe(items.reduce((total, i) => total + i.quantity_on_hand, 0));
    });

    test("only this organization's products are valued", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { items } = await responseProps(await api.get(storeUrl()));

        expect([...new Set(items.map((i) => i.domain))]).toEqual(["jollibee-corp"]);
    });

    for (const query of ["&location_id=abc", "&location_id=999999", "&location_id="]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(`${valuationPath}?x=1${query}`)).status()).toBeLessThan(500);
        });
    }

    test("a cashier can't open the valuation report", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        expect((await apiJson(api, "GET", storeUrl())).status).toBe(403);
    });

    test("another organization's manager can't open Jollibee's report", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", storeUrl())).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(storeUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
