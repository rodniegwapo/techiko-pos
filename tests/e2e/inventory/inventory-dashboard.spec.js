import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

const inventoryUrl = (query = "") => `/domains/jollibee-corp/inventory${query}`;
const fixture = () => fixtureIds().inventoryDashboard;
const storeUrl = () => inventoryUrl(`?location_id=${fixture().store.id}`);

/** A KPI card ("Total Products", "In Stock", …) at the top of the dashboard. */
const kpiCard = (page, title) =>
    page.locator("div.rounded-lg.border.p-6").filter({ has: page.locator("p", { hasText: new RegExp(`^\\s*${title}\\s*$`) }) }).first();
const kpiValue = (page, title) => kpiCard(page, title).locator("p.text-2xl");
const lowStockAlert = (page) => page.locator("div.rounded-lg.border").filter({ has: page.getByRole("heading", { name: "Low Stock Alert" }) });
const locationCode = (page) => page.locator("p", { hasText: /^\s*Location Code\s*$/ }).locator("xpath=following-sibling::p");
const quickAction = (page, title) => page.locator("div.cursor-pointer").filter({ has: page.getByRole("heading", { name: title, exact: true }) });

async function openDashboard(page, url = storeUrl()) {
    await page.goto(url);
    await expect(page.getByText("Stock Level by Category")).toBeVisible();
}

test.describe("Inventory dashboard (admin)", () => {
    test.use({ account: "admin" });

    test("the stock cards count the store's products by stock status", async ({ page }) => {
        const { summary } = fixture();
        await openDashboard(page);

        await expect(kpiValue(page, "Total Products")).toHaveText(String(summary.total));
        await expect(kpiValue(page, "In Stock")).toHaveText(String(summary.inStock));
        await expect(kpiValue(page, "Low Stock")).toHaveText(String(summary.lowStock));
        await expect(kpiValue(page, "Out of Stock")).toHaveText(String(summary.outOfStock));
    });

    test("the cards don't show made-up month-over-month changes", async ({ page }) => {
        await openDashboard(page);

        // The report has no previous-month figures, so any "vs last month" change is invented.
        await expect(page.getByText("vs last month")).toHaveCount(0);
    });

    test("shows the store's name, type, address, code and inventory value", async ({ page }) => {
        const { store, summary } = fixture();
        await openDashboard(page);

        // The header's store picker shows the name too.
        await expect(page.getByRole("main").getByText(store.name, { exact: true })).toBeVisible();
        await expect(page.getByText(`Store • ${store.address}`)).toBeVisible();
        await expect(locationCode(page)).toHaveText(store.code);
        await expect(page.getByText("Total Inventory Value").locator("xpath=following-sibling::p")).toHaveText(`₱${summary.value.toFixed(2)}`);
    });

    test("the category chart data splits each category by stock status", async ({ page }) => {
        await openDashboard(page);

        const { report } = await pageProps(page);
        for (const category of fixture().categories) {
            expect(report.category_stock_data).toContainEqual(category);
        }
        await expect(page.locator(".apexcharts-canvas")).toBeVisible();
    });

    test("the low stock alert lists the products the Low Stock card counts", async ({ page }) => {
        const { products, summary } = fixture();
        await openDashboard(page);

        const alert = lowStockAlert(page);
        await expect(alert).toContainText(`${summary.lowStock} products need attention`);
        await expect(alert.getByText(products.low.name, { exact: true })).toBeVisible();
        // Low by the store's own reorder level (10), not the product's (2).
        await expect(alert.getByText(products.storeLow.name, { exact: true }), "low by the store's reorder level").toBeVisible();
        await expect(alert.getByText(products.plenty.name, { exact: true })).toHaveCount(0);
        await expect(alert.getByText(products.empty.name, { exact: true }), "out of stock is counted separately").toHaveCount(0);
    });

    test("a low stock product shows its SKU, stock left and minimum", async ({ page }) => {
        const { low } = fixture().products;
        await openDashboard(page);

        const card = lowStockAlert(page).locator("div.bg-orange-50").filter({ hasText: low.name });
        await expect(card).toContainText(`SKU: ${low.sku}`);
        await expect(card).toContainText(`${low.qty} left`);
        await expect(card).toContainText(/Min: 5(\.00)?\b/);
    });

    test("a product low by the store's reorder level shows the store's minimum", async ({ page }) => {
        const { storeLow } = fixture().products;
        await openDashboard(page);

        const card = lowStockAlert(page).locator("div.bg-orange-50").filter({ hasText: storeLow.name });
        await expect(card).toContainText(`${storeLow.qty} left`);
        await expect(card).toContainText(/Min: 10(\.00)?\b/);
    });

    // Admins switch stores with the header's location badge, which reloads with ?location_id. The
    // badge also changes the organization's default store, so the test opens the URL directly.
    test("shows the store picked in the URL", async ({ page }) => {
        const { branchLocation } = fixture();

        await openDashboard(page, inventoryUrl(`?location_id=${branchLocation.id}`));

        await expect(page.getByRole("main").getByText(branchLocation.name, { exact: true })).toBeVisible();
        await expect(locationCode(page)).toHaveText(branchLocation.code);
    });

    for (const [title, path] of [
        ["Manage Products", "/domains/jollibee-corp/inventory/products"],
        ["Inventory Movements", "/domains/jollibee-corp/inventory/movements"],
        ["Stock Adjustments", "/domains/jollibee-corp/inventory/adjustments"],
    ]) {
        test(`"${title}" opens the organization's page`, async ({ page }) => {
            await openDashboard(page);

            await quickAction(page, title).click();

            await expect(page).toHaveURL((url) => url.pathname === path);
            await expect(page.getByText("NOT FOUND", { exact: true })).toHaveCount(0);
        });
    }

    test("Manage Products keeps the selected store", async ({ page }) => {
        await openDashboard(page);

        await quickAction(page, "Manage Products").click();

        await expect(page).toHaveURL((url) => url.pathname.endsWith("/inventory/products") && url.searchParams.get("location_id") === String(fixture().store.id));
    });

    test("View Report opens the organization's valuation report", async ({ page }) => {
        await openDashboard(page);

        await page.getByRole("button", { name: "View Report" }).click();

        await expect(page).toHaveURL((url) => url.pathname === "/domains/jollibee-corp/inventory/valuation");
    });

    test("View All Low Stock Products opens the product list filtered to low stock", async ({ page }) => {
        await openDashboard(page);

        await page.getByRole("button", { name: /View All Low Stock Products/ }).click();

        await expect(page).toHaveURL((url) => url.pathname === "/domains/jollibee-corp/inventory/products" && url.searchParams.get("stock_status") === "low_stock");
    });
});

test.describe("Inventory dashboard quick actions per role", () => {
    test.describe("as a cashier", () => {
        test.use({ account: "cashier" });

        test("only shows the actions a cashier may use", async ({ page }) => {
            await openDashboard(page, inventoryUrl());

            await expect(quickAction(page, "Manage Products")).toBeVisible();
            await expect(quickAction(page, "Inventory Movements"), "cashiers can't view movements").toHaveCount(0);
            await expect(quickAction(page, "Stock Adjustments"), "cashiers can't manage adjustments").toHaveCount(0);
            await expect(page.getByRole("button", { name: "View Report" }), "cashiers can't view the valuation report").toHaveCount(0);
        });
    });

    test.describe("as a manager", () => {
        test.use({ account: "manager" });

        test("shows products, movements and adjustments", async ({ page }) => {
            await openDashboard(page, inventoryUrl());

            await expect(quickAction(page, "Manage Products")).toBeVisible();
            await expect(quickAction(page, "Inventory Movements")).toBeVisible();
            await expect(quickAction(page, "Stock Adjustments")).toBeVisible();
            await expect(page.getByRole("button", { name: "View Report" })).toBeVisible();
        });
    });
});

test.describe("Inventory dashboard access", () => {
    test("an admin sees any of the organization's stores", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { report } = await responseProps(await api.get(storeUrl()));

        expect(report.location.code).toBe(fixture().store.code);
    });

    for (const account of ["manager", "cashier"]) {
        test(`a ${account} only sees their own store, whatever location is asked for`, async ({ serverAs }) => {
            const api = await serverAs(account);

            const { report } = await responseProps(await api.get(storeUrl()));

            expect(report.location.code).toBe(fixture().mainLocation.code);
        });
    }

    test("another organization's store can't be shown", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await api.get(inventoryUrl(`?location_id=${fixture().otherOrgLocationId}`));

        expect(res.status()).toBeLessThan(500);
        const { report } = await responseProps(res);
        expect(report.location.domain).toBe("jollibee-corp");
    });

    test("a non-numeric location is handled without an error", async ({ serverAs }) => {
        const api = await serverAs("admin");

        expect((await api.get(inventoryUrl("?location_id=abc"))).status()).toBeLessThan(500);
    });

    test("another organization's manager can't open the Jollibee dashboard", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", inventoryUrl())).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(inventoryUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
