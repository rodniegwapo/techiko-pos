import { test, expect, apiJson } from "../support/fixtures.js";
import { notice } from "../support/antd.js";
import { pageProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";
import { workerNumber } from "../support/users.js";

const categoriesUrl = (path = "") => `/domains/jollibee-corp/categories${path}`;
const categoryFixture = (testInfo) => fixtureIds().categories.workers[workerNumber(testInfo.parallelIndex)];
const uniqueName = (label) => `E2E New Cat ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const row = (page, name) => rows(page).filter({ has: page.getByText(name, { exact: true }) });

async function openCategories(page) {
    await page.goto(categoriesUrl());
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const loaded = page.waitForResponse((r) => {
        const url = new URL(r.url());
        return r.request().method() === "GET" && url.pathname === categoriesUrl() && (url.searchParams.get("search") ?? "") === text;
    });
    await page.getByPlaceholder("Input search text").fill(text);
    await loaded;
}

/** Waits for the category POST/PUT and the reload after it. */
async function saveDialog(page, buttonName) {
    const saved = page.waitForResponse((r) => ["POST", "PUT"].includes(r.request().method()) && new URL(r.url()).pathname.startsWith(categoriesUrl()));
    await page.getByRole("dialog").getByRole("button", { name: buttonName }).click();
    return saved;
}

test.describe("Categories list", () => {
    test.use({ account: "wallet-manager" });

    test("lists categories with their descriptions", async ({ page }, testInfo) => {
        const { plain } = categoryFixture(testInfo);
        await openCategories(page);

        await search(page, plain.name);

        await expect(rows(page)).toHaveCount(1);
        await expect(row(page, plain.name)).toContainText(plain.description);
    });

    test("search also matches the description", async ({ page }, testInfo) => {
        const { plain } = categoryFixture(testInfo);
        await openCategories(page);

        await search(page, plain.description);

        await expect(row(page, plain.name)).toBeVisible();
    });

    test("paginates search results 15 at a time", async ({ page }, testInfo) => {
        const { pagePrefix, pageCount } = categoryFixture(testInfo);
        await openCategories(page);
        await search(page, pagePrefix);

        await expect(rows(page)).toHaveCount(15);

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        await expect(rows(page), "the search is kept on page 2").toHaveCount(pageCount - 15);
    });

    test("a search with no match shows the empty table", async ({ page }) => {
        await openCategories(page);

        await search(page, "no-such-category-zz");

        await expect(rows(page)).toHaveCount(0);
        await expect(page.locator(".ant-empty")).toBeVisible();
    });

    test("only shows this organization's categories", async ({ page }) => {
        await openCategories(page);

        await search(page, "E2E Cat McDonalds");

        await expect(rows(page)).toHaveCount(0);
    });

    test("a manager (no delete permission) doesn't see Delete", async ({ page }, testInfo) => {
        const { plain } = categoryFixture(testInfo);
        await openCategories(page);
        await search(page, plain.name);

        await expect(row(page, plain.name).getByRole("button", { name: "Edit Category" })).toBeVisible();
        await expect(row(page, plain.name).getByRole("button", { name: "Delete Category" })).toHaveCount(0);
    });
});

test.describe("Create category", () => {
    test.use({ account: "wallet-manager" });

    test("the dialog opens without errors", async ({ page }) => {
        const warnings = [];
        page.on("console", (m) => {
            if (/modalWidth|modalRootStyle/.test(m.text())) warnings.push(m.text());
        });
        await openCategories(page);

        await page.getByRole("button", { name: "Create Category" }).click();

        await expect(page.getByRole("dialog", { name: "Add Category" })).toBeVisible();
        expect(warnings, "the dialog reads size settings that don't exist").toEqual([]);
    });

    test("a name is required, and the error shows under the field", async ({ page }) => {
        await openCategories(page);
        await page.getByRole("button", { name: "Create Category" }).click();

        await saveDialog(page, "Submit");

        await expect(notice(page, "Please review the form")).toBeVisible();
        const dialog = page.getByRole("dialog", { name: "Add Category" });
        await expect(dialog, "stays open to fix the name").toBeVisible();
        await expect(dialog.locator(".ant-form-item-explain-error")).toContainText("name field is required");
    });

    test("creates a category", async ({ page }) => {
        const name = uniqueName("Create");
        await openCategories(page);
        await page.getByRole("button", { name: "Create Category" }).click();
        const dialog = page.getByRole("dialog", { name: "Add Category" });

        await dialog.getByPlaceholder("Enter category name").fill(name);
        await dialog.getByPlaceholder("Enter category description").fill("Made by Playwright");
        const res = await saveDialog(page, "Submit");

        expect(res.status()).toBeLessThan(400);
        await expect(notice(page, "Success")).toContainText("Category created successfully");
        await expect(dialog).toBeHidden();
        await search(page, name);
        await expect(row(page, name)).toContainText("Made by Playwright");
    });

    test("the dialog is empty again after editing another category", async ({ page }, testInfo) => {
        const { plain } = categoryFixture(testInfo);
        await openCategories(page);
        await search(page, plain.name);
        await row(page, plain.name).getByRole("button", { name: "Edit Category" }).click();
        await page.getByRole("dialog").getByRole("button", { name: "Cancel" }).click();

        await page.getByRole("button", { name: "Create Category" }).click();

        await expect(page.getByRole("dialog", { name: "Add Category" }).getByPlaceholder("Enter category name")).toHaveValue("");
    });

    test("a name already used in the organization is rejected", async ({ page, serverAs }) => {
        // A throwaway category, so a wrongly accepted duplicate can't disturb the seeded ones.
        const name = uniqueName("Dup");
        await apiJson(await serverAs("wallet-manager"), "POST", categoriesUrl(), { name });
        await openCategories(page);
        await page.getByRole("button", { name: "Create Category" }).click();

        await page.getByRole("dialog").getByPlaceholder("Enter category name").fill(name);
        await saveDialog(page, "Submit");

        await expect(notice(page, "Please review the form"), "duplicate category names are confusing in product forms").toBeVisible();
    });
});

test.describe("Edit category", () => {
    test.use({ account: "wallet-manager" });

    test("opens with the category's values and saves changes", async ({ page, serverAs }) => {
        const api = await serverAs("wallet-manager");
        const name = uniqueName("Edit");
        await apiJson(api, "POST", categoriesUrl(), { name, description: "Before" });
        await openCategories(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Edit Category" }).click();
        const dialog = page.getByRole("dialog", { name: "Edit Category" });
        await expect(dialog.getByPlaceholder("Enter category name")).toHaveValue(name);
        await expect(dialog.getByPlaceholder("Enter category description")).toHaveValue("Before");

        await dialog.getByPlaceholder("Enter category name").fill(`${name} Renamed`);
        await dialog.getByPlaceholder("Enter category description").fill("After");
        const res = await saveDialog(page, "Update");

        expect(res.status()).toBeLessThan(400);
        await expect(notice(page, "Success")).toContainText("Category updated successfully");
        await search(page, `${name} Renamed`);
        await expect(row(page, `${name} Renamed`)).toContainText("After");
    });

    test("cancelling an edit leaves the row unchanged", async ({ page }, testInfo) => {
        const { plain } = categoryFixture(testInfo);
        await openCategories(page);
        await search(page, plain.name);

        await row(page, plain.name).getByRole("button", { name: "Edit Category" }).click();
        const dialog = page.getByRole("dialog", { name: "Edit Category" });
        await dialog.getByPlaceholder("Enter category description").fill("Typed but cancelled");
        await dialog.getByRole("button", { name: "Cancel" }).click();

        await expect(rows(page).first(), "the table shouldn't show unsaved edits").not.toContainText("Typed but cancelled");
        await expect(rows(page).first()).toContainText(plain.description);
    });

    test("clearing the name is rejected", async ({ page }, testInfo) => {
        const { plain } = categoryFixture(testInfo);
        await openCategories(page);
        await search(page, plain.name);
        await row(page, plain.name).getByRole("button", { name: "Edit Category" }).click();

        await page.getByRole("dialog").getByPlaceholder("Enter category name").fill("");
        await saveDialog(page, "Update");

        await expect(notice(page, "Please review the form")).toBeVisible();
        await page.reload();
        await expect(page.locator(".ant-table")).toBeVisible();
        await search(page, plain.name);
        await expect(row(page, plain.name), "the name wasn't cleared").toHaveCount(1);
    });
});

test.describe("Delete category (admin)", () => {
    test.use({ account: "admin" });

    async function clickDelete(page, name) {
        await search(page, name);
        await row(page, name).getByRole("button", { name: "Delete Category" }).click();
        const confirm = page.getByRole("dialog").filter({ hasText: "Confirm Delete" });
        await expect(confirm).toBeVisible();
        return confirm;
    }

    test("Cancel keeps the category", async ({ page, serverAs }) => {
        const name = uniqueName("Keep");
        await apiJson(await serverAs("admin"), "POST", categoriesUrl(), { name });
        await openCategories(page);

        const confirm = await clickDelete(page, name);
        await confirm.getByRole("button", { name: "Cancel" }).click();

        await expect(row(page, name)).toHaveCount(1);
    });

    test("deletes an unused category", async ({ page, serverAs }) => {
        const name = uniqueName("Delete");
        await apiJson(await serverAs("admin"), "POST", categoriesUrl(), { name });
        await openCategories(page);

        const confirm = await clickDelete(page, name);
        const deleted = page.waitForResponse((r) => r.request().method() === "DELETE");
        await confirm.getByRole("button", { name: "Delete" }).click();

        expect((await deleted).status()).toBeLessThan(400);
        await expect(notice(page, "Success")).toContainText("Category deleted successfully");
        await expect(row(page, name)).toHaveCount(0);
    });

    test("a category with products can't be deleted, and says why", async ({ page }, testInfo) => {
        const { inUse } = categoryFixture(testInfo);
        await openCategories(page);

        const confirm = await clickDelete(page, inUse.name);
        await confirm.getByRole("button", { name: "Delete" }).click();

        await expect(notice(page, "Cannot Delete")).toContainText(`Cannot delete category with ${inUse.productCount} existing products`);
        await expect(row(page, inUse.name)).toHaveCount(1);
    });
});

test.describe("Categories as a cashier", () => {
    test.use({ account: "worker-cashier" });

    test("can't open the page or see it in the sidebar", async ({ page, serverAs }) => {
        const api = await serverAs("worker-cashier");
        const res = await api.get(categoriesUrl());
        expect(new URL(res.url()).pathname, "redirected away").not.toBe(categoriesUrl());

        await page.goto("/domains/jollibee-corp/customers");
        const sidebar = page.getByRole("complementary");
        await expect(sidebar.getByText("Customers", { exact: true })).toBeVisible();
        const products = sidebar.locator(".ant-menu-submenu-title").filter({ hasText: /^\s*Products\s*$/ });
        if (await products.count()) {
            await products.first().click();
        }
        await expect(page.getByText("Categories", { exact: true }).filter({ visible: true })).toHaveCount(0);
        expect((await pageProps(page)).auth.user.data.is_super_user).toBeFalsy();
    });
});
