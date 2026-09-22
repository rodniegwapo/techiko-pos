import { test, expect, apiJson } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage, salesUrl } from "../support/sales.js";

const { products } = E2E;

test.describe("Sales page access", () => {
    test("guests are sent to login", async ({ page }) => {
        await page.goto(salesUrl());
        await expect(page).toHaveURL(/\/login$/);
    });

    for (const account of ["admin", "manager", "cashier"]) {
        test(`${account} can open the Sales page`, async ({ serverAs }) => {
            const api = await serverAs(account);
            const res = await api.get(salesUrl());

            expect(res.status()).toBe(200);
            expect(res.url()).toContain(salesUrl());
        });
    }

    test("another organization's cashier is denied", async ({ serverAs }) => {
        const api = await serverAs("mcCashier");
        const res = await apiJson(api, "GET", `${salesUrl()}/products`);

        expect(res.status).toBe(403);
    });

    test("another organization's cashier cannot open this Sales page", async ({ serverAs }) => {
        const api = await serverAs("mcCashier");
        const res = await api.get(salesUrl());

        expect(new URL(res.url()).pathname, "should be redirected away").not.toBe(salesUrl());
    });
});

for (const layout of ["classic", "coffeeshop"]) {
    test.describe(`Catalog (${layout} layout)`, () => {
        test.use({ account: "worker-cashier" });

        test("lists the store's products and search narrows them", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });

            await sales.search("E2E");
            for (const product of [products.burger, products.fries, products.limited, products.soldOut]) {
                await expect(sales.productTile(product.name)).toBeVisible();
            }

            await sales.search(products.burger.name);
            await expect(sales.productTile(products.burger.name)).toBeVisible();
            await expect(sales.productTile(products.fries.name)).toHaveCount(0);
        });

        test("a search with no match shows the empty state", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });

            await sales.search("zz-no-such-product-zz");

            await expect(page.getByText("No Item Found")).toBeVisible();
        });

        test("out-of-stock products can't be added", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.search(products.soldOut.name);

            await expect(sales.productTile(products.soldOut.name)).toContainText("Out of stock");
            await expect(sales.addButton(products.soldOut.name)).toBeDisabled();
        });

        test("shows product prices", async ({ page }) => {
            const sales = await SalesPage.open(page, { layout });
            await sales.search(products.burger.name);

            await expect(sales.productTile(products.burger.name)).toContainText("₱100.00");
        });
    });
}

test.describe("Catalog API validation", () => {
    // location_id cases run as admin: for store-restricted roles RoleBasedAccessControl replaces
    // location_id with the user's own store before validation, so there's nothing to reject.
    const cases = [
        { query: "per_page=101", field: "per_page", account: "worker-cashier" },
        { query: "per_page=0", field: "per_page", account: "worker-cashier" },
        { query: "page=0", field: "page", account: "worker-cashier" },
        { query: "location_id=999999", field: "location_id", account: "admin" },
        { query: "location_id=abc", field: "location_id", account: "admin" },
    ];

    for (const { query, field, account } of cases) {
        test(`rejects ${query}`, async ({ serverAs }) => {
            const api = await serverAs(account);
            const res = await apiJson(api, "GET", `${salesUrl()}/products?${query}`);

            expect(res.status).toBe(422);
            expect(res.body.errors).toHaveProperty(field);
        });
    }

    test("paginates with per_page", async ({ serverAs }) => {
        const api = await serverAs("worker-cashier");
        const res = await apiJson(api, "GET", `${salesUrl()}/products?search=E2E&per_page=2&page=1`);

        expect(res.status).toBe(200);
        expect(res.body.data).toHaveLength(2);
        expect(res.body.meta).toMatchObject({ per_page: 2, current_page: 1 });
        expect(res.body.meta.total).toBeGreaterThanOrEqual(4);
    });

    test("a restricted cashier only gets their own store's stock", async ({ serverAs }) => {
        const api = await serverAs("worker-cashier");
        const res = await apiJson(api, "GET", `${salesUrl()}/products?search=${encodeURIComponent(products.limited.name)}`);

        const limited = res.body.data.find((p) => p.name === products.limited.name);
        expect(limited.location_quantity_available).toBe(products.limited.stock);
    });
});
