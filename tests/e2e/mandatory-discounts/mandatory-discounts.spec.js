import { test, expect, apiJson } from "../support/fixtures.js";
import { notice, pickSelectOption } from "../support/antd.js";
import { responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";
import { workerNumber } from "../support/users.js";

const mandatoryUrl = (path = "") => `/domains/jollibee-corp/mandatory-discounts${path}`;
const mandatoryFixture = (testInfo) => fixtureIds().mandatoryPage.workers[workerNumber(testInfo.parallelIndex)];
const uniqueName = (label) => `E2E New Mand ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const row = (page, name) => rows(page).filter({ has: page.getByText(name, { exact: true }) });

async function openMandatory(page) {
    await page.goto(mandatoryUrl());
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const box = page.getByPlaceholder("Search mandatory discount");
    // Re-entering the same text sends no request, so clear it first to force a fresh list.
    if ((await box.inputValue()) === text) {
        const cleared = page.waitForResponse((r) => new URL(r.url()).pathname === mandatoryUrl() && !new URL(r.url()).searchParams.get("search"));
        await box.fill("");
        await cleared;
    }
    const loaded = page.waitForResponse((r) => {
        const url = new URL(r.url());
        return r.request().method() === "GET" && url.pathname === mandatoryUrl() && (url.searchParams.get("search") ?? "") === text;
    });
    await box.fill(text);
    await loaded;
}

async function savedRecord(serverAs, name) {
    const admin = await serverAs("admin");
    return (await responseProps(await admin.get(`${mandatoryUrl()}?search=${encodeURIComponent(name)}`))).items.data[0];
}

/** The Create/Edit mandatory discount dialog. */
class MandatoryDialog {
    constructor(page) {
        this.page = page;
        this.dialog = page.getByRole("dialog").filter({ has: page.getByText(/^(Create|Edit) Mandatory Discount$/) });
    }

    item(label) {
        return this.dialog.locator(".ant-form-item").filter({ has: this.page.locator(`label:text-is("${label}")`) });
    }

    async fill({ name, type, value, status } = {}) {
        if (name !== undefined) await this.dialog.getByPlaceholder("e.g., Senior Citizen, PWD, Student").fill(name);
        if (type) await pickSelectOption(this.page, this.item("Discount Type"), 0, type);
        if (value !== undefined) await this.dialog.getByPlaceholder("Enter discount value").fill(String(value));
        if (status) await pickSelectOption(this.page, this.item("Status"), 0, status);
    }

    async save(buttonName) {
        const saved = this.page.waitForResponse((r) => ["POST", "PUT"].includes(r.request().method()) && new URL(r.url()).pathname.includes("mandatory-discounts"));
        await this.dialog.getByRole("button", { name: buttonName }).click();
        return saved;
    }

    error(label) {
        return this.item(label).locator(".ant-form-item-explain-error");
    }
}

test.describe("Mandatory discounts list", () => {
    test.use({ account: "admin" });

    test("search finds a mandatory discount by name", async ({ page }, testInfo) => {
        const { percent } = mandatoryFixture(testInfo);
        await openMandatory(page);

        await search(page, percent.name);

        await expect(rows(page)).toHaveCount(1);
    });

    test("a percentage discount shows its type, percent value and Active status", async ({ page }, testInfo) => {
        const { percent } = mandatoryFixture(testInfo);
        await openMandatory(page);
        await search(page, percent.name);

        const line = row(page, percent.name);
        await expect(line).toContainText("Percentage");
        await expect(line).toContainText("12.5%");
        await expect(line).toContainText("Active");
    });

    test("an amount discount shows its value in pesos and Inactive status", async ({ page }, testInfo) => {
        const { amount } = mandatoryFixture(testInfo);
        await openMandatory(page);
        await search(page, amount.name);

        const line = row(page, amount.name);
        await expect(line).toContainText("Amount");
        await expect(line).toContainText("₱75.00");
        await expect(line).toContainText("Inactive");
    });

    test("only shows this organization's mandatory discounts", async ({ page }) => {
        await openMandatory(page);

        await search(page, fixtureIds().mandatoryPage.otherOrg.name);

        await expect(rows(page)).toHaveCount(0);
    });

    test("View Mandatory Discount shows the details", async ({ page }, testInfo) => {
        const { amount } = mandatoryFixture(testInfo);
        await openMandatory(page);
        await search(page, amount.name);

        await row(page, amount.name).getByRole("button", { name: "View Mandatory Discount" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Mandatory Discount Details" });
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText("Fixed Amount");
        await expect(dialog).toContainText("₱75.00");
        await expect(dialog).toContainText("Currently inactive");
        await expect(dialog).not.toContainText("Invalid Date");
    });
});

test.describe("Create mandatory discount (admin)", () => {
    test.use({ account: "admin" });

    test("required fields are explained under each field", async ({ page }) => {
        await openMandatory(page);
        await page.getByRole("button", { name: "Create Mandatory Discount" }).click();
        const dialog = new MandatoryDialog(page);

        await dialog.save("Submit");

        await expect(notice(page, "Please review the form")).toBeVisible();
        await expect(dialog.error("Discount Name")).toContainText("name field is required");
        await expect(dialog.error("Discount Type")).toContainText("type field is required");
        await expect(dialog.error("Discount Value")).toContainText("value field is required");
    });

    test("creates a percentage mandatory discount in this organization", async ({ page, serverAs }) => {
        const name = uniqueName("Create");
        await openMandatory(page);
        await page.getByRole("button", { name: "Create Mandatory Discount" }).click();
        const dialog = new MandatoryDialog(page);

        await dialog.fill({ name, type: "Percentage", value: 15 });
        const res = await dialog.save("Submit");

        expect(res.status(), `saving answered ${res.status()} from ${new URL(res.url()).pathname}`).toBeLessThan(400);
        await expect(notice(page, "Success")).toContainText("created");
        await expect(dialog.dialog).toBeHidden();
        await search(page, name);
        await expect(row(page, name)).toContainText("15%");
        await expect(row(page, name), "status defaults to Active").toContainText("Active");
        expect((await savedRecord(serverAs, name))?.domain).toBe("jollibee-corp");
    });

    test("creates an inactive amount mandatory discount", async ({ page }) => {
        const name = uniqueName("Inactive");
        await openMandatory(page);
        await page.getByRole("button", { name: "Create Mandatory Discount" }).click();
        const dialog = new MandatoryDialog(page);

        await dialog.fill({ name, type: "Amount", value: 30, status: "Inactive" });
        await dialog.save("Submit");

        await expect(dialog.dialog).toBeHidden();
        await search(page, name);
        await expect(row(page, name)).toContainText("₱30.00");
        await expect(row(page, name)).toContainText("Inactive");
    });

    test("a percentage over 100 is rejected", async ({ page }) => {
        await openMandatory(page);
        await page.getByRole("button", { name: "Create Mandatory Discount" }).click();
        const dialog = new MandatoryDialog(page);

        await dialog.fill({ name: uniqueName("Over100"), type: "Percentage", value: 150 });
        await dialog.save("Submit");

        await expect(dialog.error("Discount Value"), "a discount can't take more than 100% off").not.toHaveCount(0);
    });
});

test.describe("Edit and delete mandatory discount (admin)", () => {
    test.use({ account: "admin" });

    async function createMandatory(serverAs, body) {
        const admin = await serverAs("admin");
        const res = await apiJson(admin, "POST", mandatoryUrl(), body);
        expect(res.status, `seed mandatory discount: ${JSON.stringify(res.body)?.slice(0, 160)}`).toBeLessThan(400);
    }

    test("opens with the discount's values and saves a change", async ({ page, serverAs }) => {
        const name = uniqueName("Edit");
        await createMandatory(serverAs, { name, type: "amount", value: 20, is_active: true });
        await openMandatory(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Edit Mandatory Discount" }).click();
        const dialog = new MandatoryDialog(page);
        await expect(dialog.dialog.getByPlaceholder("e.g., Senior Citizen, PWD, Student")).toHaveValue(name);
        await expect(dialog.item("Discount Type").locator(".ant-select-selection-item")).toHaveText("Amount");
        await expect(dialog.item("Status").locator(".ant-select-selection-item")).toHaveText("Active");

        await dialog.fill({ value: 35, status: "Inactive" });
        const res = await dialog.save("Update");

        expect(res.status(), `saving answered ${res.status()} from ${new URL(res.url()).pathname}`).toBeLessThan(400);
        await expect(notice(page, "Success")).toContainText("Mandatory discount updated successfully");
        const saved = await savedRecord(serverAs, name);
        expect(Number(saved.value)).toBe(35);
        expect(saved.is_active).toBe(false);
    });

    test("Cancel on delete keeps the mandatory discount", async ({ page, serverAs }) => {
        const name = uniqueName("Keep");
        await createMandatory(serverAs, { name, type: "amount", value: 1 });
        await openMandatory(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Delete Mandatory Discount" }).click();
        const confirm = page.getByRole("dialog").filter({ hasText: "Confirm Delete" });
        await confirm.getByRole("button", { name: "Cancel" }).click();

        await expect(row(page, name)).toHaveCount(1);
    });

    test("deletes a mandatory discount after confirming", async ({ page, serverAs }) => {
        const name = uniqueName("Delete");
        await createMandatory(serverAs, { name, type: "amount", value: 1 });
        await openMandatory(page);
        await search(page, name);

        await row(page, name).getByRole("button", { name: "Delete Mandatory Discount" }).click();
        await page.getByRole("dialog").filter({ hasText: "Confirm Delete" }).getByRole("button", { name: "Delete" }).click();

        await expect(notice(page, "Success")).toContainText("Mandatory discount deleted successfully");
        await expect(row(page, name)).toHaveCount(0);
    });
});

test.describe("Mandatory discounts as a manager (view only)", () => {
    test.use({ account: "manager" });

    test("can view mandatory discounts but not create, edit or delete", async ({ page }, testInfo) => {
        const { percent } = mandatoryFixture(testInfo);
        await openMandatory(page);
        await search(page, percent.name);

        await expect(row(page, percent.name)).toBeVisible();
        await expect(page.getByRole("button", { name: "Create Mandatory Discount" })).toHaveCount(0);
        await expect(row(page, percent.name).getByRole("button", { name: "Edit Mandatory Discount" })).toHaveCount(0);
        await expect(row(page, percent.name).getByRole("button", { name: "Delete Mandatory Discount" })).toHaveCount(0);
        await expect(row(page, percent.name).getByRole("button", { name: "View Mandatory Discount" })).toBeVisible();
    });
});
