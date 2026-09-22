import { test, expect, apiJson } from "../support/fixtures.js";
import { notice, pickSelectOption } from "../support/antd.js";
import { fixtureIds } from "../support/sales.js";
import { workerNumber } from "../support/users.js";

const discountsUrl = (path = "") => `/domains/jollibee-corp/discounts${path}`;
const discountFixture = (testInfo) => fixtureIds().discountPage.workers[workerNumber(testInfo.parallelIndex)];
const uniqueName = (label) => `E2E New Disc ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const row = (page, name) => rows(page).filter({ has: page.getByText(name, { exact: true }) });

async function openDiscounts(page) {
    await page.goto(discountsUrl());
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const box = page.getByPlaceholder("Search discount");
    // Re-entering the same text sends no request, so clear it first to force a fresh list.
    if ((await box.inputValue()) === text) {
        const cleared = page.waitForResponse((r) => new URL(r.url()).pathname === discountsUrl() && !new URL(r.url()).searchParams.get("search"));
        await box.fill("");
        await cleared;
    }
    const loaded = page.waitForResponse((r) => {
        const url = new URL(r.url());
        return r.request().method() === "GET" && url.pathname === discountsUrl() && (url.searchParams.get("search") ?? "") === text;
    });
    await box.fill(text);
    await loaded;
}

/** The Add/Edit discount dialog. */
class DiscountDialog {
    constructor(page) {
        this.page = page;
        this.dialog = page.getByRole("dialog").filter({ has: page.getByText(/^(Add|Edit) Discount$/) });
    }

    item(label) {
        return this.dialog.locator(".ant-form-item").filter({ has: this.page.locator(`label:text-is("${label}")`) });
    }

    async fill({ name, type, value, minOrder, scope, start, end } = {}) {
        if (name !== undefined) await this.dialog.getByPlaceholder("Enter discount name").fill(name);
        if (type) await this.item("Discount Type").locator(".ant-radio-wrapper").filter({ hasText: type }).click();
        if (value !== undefined) await this.dialog.getByPlaceholder("Enter discount value").fill(String(value));
        if (minOrder !== undefined) await this.dialog.getByPlaceholder("Enter minimum order amount (optional)").fill(String(minOrder));
        if (scope) await pickSelectOption(this.page, this.item("Scope"), 0, scope);
        for (const [placeholder, dateTime] of [["Select start date and time", start], ["Select end date and time", end]]) {
            if (!dateTime) continue;
            const input = this.dialog.getByPlaceholder(placeholder);
            await input.click();
            await input.fill(dateTime);
            await input.press("Enter");
        }
    }

    async save(buttonName) {
        const saved = this.page.waitForResponse((r) => ["POST", "PUT"].includes(r.request().method()) && new URL(r.url()).pathname.startsWith(discountsUrl()));
        await this.dialog.getByRole("button", { name: buttonName }).click();
        return saved;
    }

    error(label) {
        return this.item(label).locator(".ant-form-item-explain-error");
    }
}

test.describe("Discounts list", () => {
    test.use({ account: "admin" });

    test("search finds a discount by name", async ({ page }, testInfo) => {
        const { amount } = discountFixture(testInfo);
        await openDiscounts(page);

        await search(page, amount.name);

        await expect(rows(page)).toHaveCount(1);
    });

    test("an amount discount shows its value in pesos", async ({ page }, testInfo) => {
        const { amount } = discountFixture(testInfo);
        await openDiscounts(page);
        await search(page, amount.name);

        await expect(row(page, amount.name)).toContainText("₱50.00");
        await expect(row(page, amount.name)).not.toContainText("50%");
    });

    test("a percentage discount shows its value as a percent", async ({ page }, testInfo) => {
        const { percent } = discountFixture(testInfo);
        await openDiscounts(page);
        await search(page, percent.name);

        await expect(row(page, percent.name)).toContainText("15%");
    });

    test("a discount without dates doesn't show Invalid Date", async ({ page }, testInfo) => {
        const { amount } = discountFixture(testInfo);
        await openDiscounts(page);
        await search(page, amount.name);

        await expect(row(page, amount.name)).not.toContainText("Invalid Date");
    });

    test("only shows this organization's discounts", async ({ page }) => {
        await openDiscounts(page);

        await search(page, fixtureIds().discountPage.otherOrg.name);

        await expect(rows(page)).toHaveCount(0);
    });

    test("View Discount shows the details", async ({ page }, testInfo) => {
        const { percent } = discountFixture(testInfo);
        await openDiscounts(page);
        await search(page, percent.name);

        await row(page, percent.name).getByRole("button", { name: "View Discount" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Discount Details" });
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText("15.00%");
        await expect(dialog).toContainText("Product Level");
        await expect(dialog).toContainText("December 31, 2030");
    });
});

test.describe("Create discount (admin)", () => {
    test.use({ account: "admin" });

    test("required fields are explained under each field", async ({ page }) => {
        await openDiscounts(page);
        await page.getByRole("button", { name: "Create Discount" }).click();
        const dialog = new DiscountDialog(page);

        await dialog.save("Submit");

        await expect(notice(page, "Please review the form")).toBeVisible();
        await expect(dialog.error("Discount Name")).toContainText("name field is required");
        await expect(dialog.error("Discount Type")).toContainText("type field is required");
        await expect(dialog.error("Discount Value")).toContainText("value field is required");
        await expect(dialog.error("Scope")).toContainText("scope field is required");
    });

    test("creates a percentage discount with a date range", async ({ page }) => {
        const name = uniqueName("Create");
        await openDiscounts(page);
        await page.getByRole("button", { name: "Create Discount" }).click();
        const dialog = new DiscountDialog(page);

        await dialog.fill({ name, type: "Percentage", value: 12, scope: "Order", start: "2026-02-01 08:00:00", end: "2030-02-01 08:00:00" });
        const res = await dialog.save("Submit");

        expect(res.status()).toBeLessThan(400);
        await expect(notice(page, "Success")).toBeVisible();
        await expect(dialog.dialog).toBeHidden();
        await search(page, name);
        await expect(row(page, name)).toContainText("12%");
    });

    test("an end date before the start date is rejected", async ({ page }) => {
        await openDiscounts(page);
        await page.getByRole("button", { name: "Create Discount" }).click();
        const dialog = new DiscountDialog(page);

        await dialog.fill({ name: uniqueName("Dates"), type: "Amount", value: 5, scope: "Order", start: "2027-05-01 00:00:00", end: "2027-04-01 00:00:00" });
        await dialog.save("Submit");

        await expect(dialog.error("End Date")).toContainText("must be a date after start date");
    });

    test("a percentage over 100 is rejected", async ({ page }) => {
        await openDiscounts(page);
        await page.getByRole("button", { name: "Create Discount" }).click();
        const dialog = new DiscountDialog(page);

        await dialog.fill({ name: uniqueName("Over100"), type: "Percentage", value: 150, scope: "Order" });
        await dialog.save("Submit");

        await expect(dialog.error("Discount Value"), "a discount can't take more than 100% off").not.toHaveCount(0);
    });
});

test.describe("Edit and delete discount (admin)", () => {
    test.use({ account: "admin" });

    async function createDiscount(serverAs, body) {
        const admin = await serverAs("admin");
        const res = await apiJson(admin, "POST", discountsUrl(), body);
        expect(res.status, `seed discount: ${JSON.stringify(res.body)?.slice(0, 160)}`).toBeLessThan(400);
    }

    test("opens with the discount's values and saves a change", async ({ page, serverAs }) => {
        const name = uniqueName("Edit");
        await createDiscount(serverAs, { name, type: "amount", value: 20, scope: "order", start_date: "2026-03-01 00:00:00", end_date: "2030-03-01 00:00:00" });
        await openDiscounts(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Edit Discount" }).click();
        const dialog = new DiscountDialog(page);
        await expect(dialog.dialog.getByPlaceholder("Enter discount name")).toHaveValue(name);
        await expect(dialog.item("Discount Type").locator(".ant-radio-wrapper-checked")).toHaveText("Amount");

        await dialog.fill({ value: 35 });
        const res = await dialog.save("Update");

        expect(res.status()).toBeLessThan(400);
        await expect(notice(page, "Success")).toContainText("Discount updated successfully");
        // The value's formatting is covered by the list tests; here only that the new value saved.
        const admin = await serverAs("admin");
        const { responseProps } = await import("../support/inertia.js");
        const saved = (await responseProps(await admin.get(`${discountsUrl()}?search=${encodeURIComponent(name)}`))).items.data[0];
        expect(Number(saved.value)).toBe(35);
    });

    test("a discount without dates can be edited", async ({ page, serverAs }) => {
        const name = uniqueName("NoDates");
        await createDiscount(serverAs, { name, type: "amount", value: 10, scope: "order" });
        await openDiscounts(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Edit Discount" }).click();
        const dialog = new DiscountDialog(page);
        await dialog.fill({ value: 11 });
        await dialog.save("Update");

        await expect(notice(page, "Success"), "saving shouldn't fail on the empty dates").toContainText("Discount updated successfully");
    });

    test("Cancel on delete keeps the discount", async ({ page, serverAs }) => {
        const name = uniqueName("Keep");
        await createDiscount(serverAs, { name, type: "amount", value: 1, scope: "order" });
        await openDiscounts(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Delete Discount" }).click();
        const confirm = page.getByRole("dialog").filter({ hasText: "Confirm Delete" });
        await confirm.getByRole("button", { name: "Cancel" }).click();

        await expect(row(page, name)).toHaveCount(1);
    });

    test("deletes a discount after confirming", async ({ page, serverAs }) => {
        const name = uniqueName("Delete");
        await createDiscount(serverAs, { name, type: "amount", value: 1, scope: "order" });
        await openDiscounts(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Delete Discount" }).click();
        await page.getByRole("dialog").filter({ hasText: "Confirm Delete" }).getByRole("button", { name: "Delete" }).click();

        await expect(notice(page, "Success")).toContainText("Discount deleted successfully");
        await expect(row(page, name)).toHaveCount(0);
    });
});

for (const account of ["manager", "worker-cashier"]) {
    test.describe(`Discounts as ${account === "manager" ? "a manager" : "a cashier"} (view only)`, () => {
        test.use({ account });

        test("can view discounts but not create, edit or delete", async ({ page }, testInfo) => {
            const { amount } = discountFixture(testInfo);
            await openDiscounts(page);
            await search(page, amount.name);

            await expect(row(page, amount.name)).toBeVisible();
            await expect(page.getByRole("button", { name: "Create Discount" })).toHaveCount(0);
            await expect(row(page, amount.name).getByRole("button", { name: "Edit Discount" })).toHaveCount(0);
            await expect(row(page, amount.name).getByRole("button", { name: "Delete Discount" })).toHaveCount(0);
            await expect(row(page, amount.name).getByRole("button", { name: "View Discount" })).toBeVisible();
        });
    });
}
