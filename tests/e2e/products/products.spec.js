import { test, expect, apiJson } from "../support/fixtures.js";
import { toast } from "../support/antd.js";
import { pageProps } from "../support/inertia.js";
import { ProductForm, ProductsPage, newProductValues, productFixture, productUrl, productsFixtures, productsUrl } from "../support/products.js";

test.describe("Products list", () => {
    test.use({ account: "wallet-manager" });

    test("shows the store's products, 15 per page", async ({ page }, testInfo) => {
        const store = productFixture(testInfo);
        const products = await ProductsPage.open(page);

        await expect(products.rows).toHaveCount(15);
        // At least the seeded catalog; other tests on this worker may have added products.
        const total = (await pageProps(page)).items.meta.total;
        expect(total).toBeGreaterThanOrEqual(store.total);

        await products.goToPage(2);
        await expect(products.rows).toHaveCount(Math.min(total - 15, 15));
    });

    test("shows price, cost, category, SKU and store quantity", async ({ page }, testInfo) => {
        const { a } = productFixture(testInfo);
        const products = await ProductsPage.open(page);
        await products.search(a.name);

        const row = products.row(a.name);
        await expect(row).toContainText(String(a.price));
        await expect(row).toContainText(String(a.cost));
        await expect(row).toContainText(a.category);
        await expect(row).toContainText(a.sku);
        await expect(row).toContainText(String(a.qty));
    });

    test("an uncategorized product says so", async ({ page }, testInfo) => {
        const { c } = productFixture(testInfo);
        const products = await ProductsPage.open(page);
        await products.search(c.name);

        await expect(products.row(c.name)).toContainText("Uncategorized");
    });

    test("only lists products offered at the manager's store", async ({ page }) => {
        const products = await ProductsPage.open(page);

        await products.search("E2E Burger");

        await expect(products.rows, "E2E Burger is only at JB-MAIN").toHaveCount(0);
    });

    test("search matches name and SKU", async ({ page }, testInfo) => {
        const { a, b } = productFixture(testInfo);
        const products = await ProductsPage.open(page);

        await products.search(b.name);
        await expect(products.rows).toHaveCount(1);

        await products.search(a.sku);
        await expect(products.rows).toHaveCount(1);
        await expect(products.row(a.name)).toBeVisible();
    });

    test("the category filter narrows the list", async ({ page }, testInfo) => {
        const { b } = productFixture(testInfo);
        const products = await ProductsPage.open(page);

        await products.filter("Category", "E2E Snacks");

        await expect(products.rows).toHaveCount(1);
        await expect(products.row(b.name)).toBeVisible();
    });

    test("the sold type filter narrows the list", async ({ page }, testInfo) => {
        const { b } = productFixture(testInfo);
        const products = await ProductsPage.open(page);

        await products.filter("Sold Type", "Box");

        await expect(products.rows, "only Box products").toHaveCount(1);
        await expect(products.row(b.name)).toBeVisible();
    });

    test("View Details shows the product's information", async ({ page }, testInfo) => {
        const { a, storeName } = productFixture(testInfo);
        const products = await ProductsPage.open(page);
        await products.search(a.name);

        await products.row(a.name).getByRole("button", { name: "View Details" }).click();

        const dialog = page.getByRole("dialog", { name: "Product Details" });
        await expect(dialog).toContainText(a.name);
        await expect(dialog).toContainText(a.sku);
        await expect(dialog).toContainText(a.category);
        await expect(dialog).toContainText(a.soldType);
        await expect(dialog).toContainText(storeName);
        await expect(dialog).toContainText("40.0%"); // (100 - 60) / 100
    });

    test("a manager (no delete permission) doesn't see Delete", async ({ page }, testInfo) => {
        const { a } = productFixture(testInfo);
        const products = await ProductsPage.open(page);
        await products.search(a.name);

        await expect(products.row(a.name).getByRole("button", { name: "Edit Product" })).toBeVisible();
        await expect(products.row(a.name).getByRole("button", { name: "Delete Product" })).toHaveCount(0);
    });
});

test.describe("Products list as a cashier (view only)", () => {
    test.use({ account: "worker-cashier" });

    test("can view products but not create, edit or delete them", async ({ page }) => {
        const products = await ProductsPage.open(page);

        await expect(products.rows.first()).toBeVisible();
        await expect(page.getByRole("button", { name: "Create Product" })).toHaveCount(0);
        await expect(products.rows.first().getByRole("button", { name: "Edit Product" })).toHaveCount(0);
        await expect(products.rows.first().getByRole("button", { name: "Delete Product" })).toHaveCount(0);
    });
});

test.describe("Create product", () => {
    test.use({ account: "wallet-manager" });

    async function openCreate(page) {
        await page.goto(productsUrl("/create"));
        await expect(page.getByPlaceholder("Enter product name")).toBeVisible();
        return new ProductForm(page);
    }

    test("opens from the list and returns with Back to Products", async ({ page }) => {
        await ProductsPage.open(page);

        await page.getByRole("button", { name: "Create Product" }).click();
        await expect(page).toHaveURL(new RegExp(`${productsUrl("/create")}`));

        await page.getByRole("button", { name: "Back to Products" }).click();
        await expect(page).toHaveURL(new RegExp(`${productsUrl()}(\\?|$)`));
    });

    test("required fields are explained", async ({ page }) => {
        const form = await openCreate(page);

        const res = await form.submit("Create Product");

        expect(res.status()).toBeLessThan(400);
        await expect(toast(page, "Please fix")).toBeVisible();
        await expect(form.error("Product Name")).toContainText("product name field is required", { timeout: 15_000 });
        await expect(form.error("Price")).toContainText("price field is required");
        await expect(form.error("Sold Type")).toContainText("sold type field is required");
    });

    test("creates a product and it appears in the store's list", async ({ page }, testInfo) => {
        const values = newProductValues("Create");
        const form = await openCreate(page);

        // "E2E Products", not "E2E Snacks": the category filter test expects Snacks to hold one product.
        await form.fill({ ...values, category: "E2E Products", cost: 12.5, price: 19.99, soldType: "Dozen", color: "ff5733" });
        await form.submit("Create Product");

        await expect(toast(page, "Product created successfully")).toBeVisible();
        await expect(page.getByPlaceholder("Enter product name"), "the form resets for the next product").toHaveValue("");

        const products = await ProductsPage.open(page);
        await products.search(values.name);
        const row = products.row(values.name);
        await expect(row).toContainText("19.99");
        await expect(row).toContainText("E2E Products");
        await expect(row).toContainText(values.sku);
        expect((await pageProps(page)).currentLocation.id).toBe(productFixture(testInfo).storeId);
    });

    test("a product name already used at the store is rejected", async ({ page }, testInfo) => {
        const { a } = productFixture(testInfo);
        const values = newProductValues("DupName");
        const form = await openCreate(page);

        await form.fill({ ...values, name: a.name, price: 10, soldType: "Piece" });
        await form.submit("Create Product");

        await expect(form.error("Product Name")).toContainText("already exists in the selected location", { timeout: 15_000 });
    });

    test("an SKU already in use is rejected", async ({ page }, testInfo) => {
        const { a } = productFixture(testInfo);
        const values = newProductValues("DupSku");
        const form = await openCreate(page);

        await form.fill({ ...values, sku: a.sku, price: 10, soldType: "Piece" });
        await form.submit("Create Product");

        await expect(form.error("SKU")).toContainText("already been taken", { timeout: 15_000 });
    });

    test("a barcode already in use in the organization is rejected", async ({ page }, testInfo) => {
        const { a } = productFixture(testInfo);
        const values = newProductValues("DupBarcode");
        const form = await openCreate(page);

        await form.fill({ ...values, barcode: a.barcode, price: 10, soldType: "Piece" });
        await form.submit("Create Product");

        await expect(form.error("Barcode")).toContainText("already been taken", { timeout: 15_000 });
    });

    test("several products can be created without a barcode", async ({ page }) => {
        const form = await openCreate(page);

        for (const label of ["NoBarcode1", "NoBarcode2"]) {
            const { name, sku } = newProductValues(label);
            await form.fill({ name, sku, price: 5, soldType: "Piece" });
            const res = await form.submit("Create Product");

            expect(res.status(), `${label}: barcode is optional`).toBeLessThan(500);
            await expect(toast(page, "Product created successfully").last(), `${label} created`).toBeVisible();
        }
    });
});

test.describe("Edit product", () => {
    test.use({ account: "wallet-manager" });

    test("opens from the list with the product's values", async ({ page }, testInfo) => {
        const { b } = productFixture(testInfo);
        const products = await ProductsPage.open(page);
        await products.search(b.name);

        await products.row(b.name).getByRole("button", { name: "Edit Product" }).click();

        await expect(page).toHaveURL(new RegExp(productUrl(b.id, "/edit")));
        await expect(page.getByPlaceholder("Enter product name")).toHaveValue(b.name);
        await expect(page.getByPlaceholder("Enter SKU")).toHaveValue(b.sku);
    });

    test("updates the price and name", async ({ page, serverAs }) => {
        const api = await serverAs("wallet-manager");
        const values = newProductValues("Edit");
        const created = await apiJson(api, "POST", productsUrl(), { name: values.name, SKU: values.sku, barcode: values.barcode, price: 10, sold_type: "Piece" });
        expect(created.status).toBeLessThan(400);
        const products = await ProductsPage.open(page);
        await products.search(values.name);
        await products.row(values.name).getByRole("button", { name: "Edit Product" }).click();
        await expect(page.getByPlaceholder("Enter product name")).toHaveValue(values.name);

        const form = new ProductForm(page);
        await form.fill({ name: `${values.name} Renamed`, price: 42 });
        await form.submit("Update Product");

        await expect(toast(page, "Product updated successfully")).toBeVisible();
        const list = await ProductsPage.open(page);
        await list.search(`${values.name} Renamed`);
        await expect(list.row(`${values.name} Renamed`)).toContainText("42");
    });

    test("clearing the name is rejected", async ({ page }, testInfo) => {
        const { c } = productFixture(testInfo);
        await page.goto(productUrl(c.id, "/edit"));
        const form = new ProductForm(page);

        await form.fill({ name: "" });
        await form.submit("Update Product");

        await expect(form.error("Product Name")).toContainText("product name field is required");
    });
});

test.describe("Delete product (admin)", () => {
    test.use({ account: "admin" });

    test("asks for confirmation, and Cancel keeps the product", async ({ page, serverAs }, testInfo) => {
        const store = productFixture(testInfo);
        const admin = await serverAs("admin");
        const values = newProductValues("KeepMe");
        await apiJson(admin, "POST", `${productsUrl()}?location_id=${store.storeId}`, {
            name: values.name, SKU: values.sku, barcode: values.barcode, price: 1, sold_type: "Piece", location_id: store.storeId,
        });
        const products = await ProductsPage.open(page, `?location_id=${store.storeId}`);
        await products.search(values.name);

        await products.row(values.name).getByRole("button", { name: "Delete Product" }).click();
        const confirm = page.getByRole("dialog").filter({ hasText: "Confirm Delete" });
        await expect(confirm).toBeVisible();
        const uncaught = [];
        page.on("pageerror", (e) => uncaught.push(e.message));
        await confirm.getByRole("button", { name: "Cancel" }).click();

        await expect(products.row(values.name)).toHaveCount(1);
        expect(uncaught, "cancelling shouldn't throw an uncaught error").toEqual([]);
    });

    test("deletes a product after confirming", async ({ page, serverAs }, testInfo) => {
        const store = productFixture(testInfo);
        const admin = await serverAs("admin");
        const values = newProductValues("DeleteMe");
        await apiJson(admin, "POST", `${productsUrl()}?location_id=${store.storeId}`, {
            name: values.name, SKU: values.sku, barcode: values.barcode, price: 1, sold_type: "Piece", location_id: store.storeId,
        });
        const products = await ProductsPage.open(page, `?location_id=${store.storeId}`);
        await products.search(values.name);

        await products.row(values.name).getByRole("button", { name: "Delete Product" }).click();
        const deleted = page.waitForResponse((r) => r.request().method() === "DELETE");
        await page.getByRole("dialog").filter({ hasText: "Confirm Delete" }).getByRole("button", { name: "Delete" }).click();

        expect((await deleted).status()).toBeLessThan(400);
        await expect(products.row(values.name)).toHaveCount(0);
    });
});

test.describe("Add existing product to a store (admin)", () => {
    test.use({ account: "admin" });

    test("attaches a product from another store", async ({ page, serverAs }, testInfo) => {
        const { attach } = productFixture(testInfo);
        const { branchLocationId } = productsFixtures();
        const admin = await serverAs("admin");
        const offered = await apiJson(admin, "GET", `${productsUrl("/assignable")}?location_id=${branchLocationId}&search=${encodeURIComponent(attach.name)}`);
        test.skip(!offered.body.data.some((p) => p.id === attach.id), "already attached earlier in this run; the seeder detaches it next run");
        const products = await ProductsPage.open(page, `?location_id=${branchLocationId}`);

        await page.getByRole("button", { name: "Add existing to store" }).click();
        const dialog = page.getByRole("dialog", { name: "Add existing product to this store" });
        const searched = page.waitForResponse((r) => r.url().includes("/products/assignable") && r.url().includes("search="));
        await dialog.getByPlaceholder("Search by name, SKU, barcode…").fill(attach.name);
        await searched;
        await expect(dialog.getByText(attach.name, { exact: true })).toBeVisible();

        const attached = page.waitForResponse((r) => r.url().includes(`/products/${attach.id}/attach-location`));
        await dialog.getByRole("button", { name: "Add to store" }).first().click();

        expect((await attached).status()).toBe(200);
        await expect(toast(page, `${attach.name} added to this store.`)).toBeVisible();
        await products.search(attach.name);
        await expect(products.row(attach.name)).toHaveCount(1);

        const again = await apiJson(admin, "GET", `${productsUrl("/assignable")}?location_id=${branchLocationId}&search=${encodeURIComponent(attach.name)}`);
        expect(again.body.data.map((p) => p.id), "no longer offered once attached").not.toContain(attach.id);
    });
});
