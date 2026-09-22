import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption, notice } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The customers page (Customers).
 *
 * The customer fixtures live in database/seeders/E2ELoyaltySeeder.php, which both this page and the
 * loyalty page read: members with fixed tiers, points and lifetime spending, one holding no points
 * and one registered two years ago, so the tier, loyalty status and registration period filters
 * each have something to include and something to leave out.
 *
 * Customers created here are removed afterwards as a super user, because no organization role may
 * delete a customer (see RolePermissionSeeder) and the page offers no delete either.
 */

const fixture = () => fixtureIds().loyalty;
const members = () => fixture().members;
const customersPath = "/domains/jollibee-corp/customers";
const listUrl = (query = "") => `${customersPath}${query}`;

const peso = (amount) => `₱${Number(amount).toLocaleString("en-US", { minimumFractionDigits: 2 })}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const cell = (row, name) =>
    row.locator("td").nth(["customer", "contact", "loyalty", "stats", "since"].indexOf(name));

/** Customers created by the test that is running; removed again in afterEach. */
const created = [];

async function openCustomers(page, url = listUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await page.getByPlaceholder("Search customers by name, email, or phone...").fill(text);
    await found;
}

/** Picks a value in the filter popover and waits for the list it asks for. */
async function filterBy(page, label, option, param, value) {
    await page.locator("button:has(.anticon-filter)").click();
    const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
    const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get(param) === value);
    await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: label }), 0, option);
    await loaded;
}

/** Adds a customer through the page's own form. */
async function addCustomer(page, { name, email, phone }) {
    await page.getByRole("button", { name: "Add Customer" }).click();
    const dialog = page.getByRole("dialog").filter({ hasText: "Add New Customer" });
    await expect(dialog).toBeVisible();

    await dialog.getByRole("textbox", { name: /Full Name/ }).fill(name);
    if (email) {
        await dialog.getByRole("textbox", { name: /Email/ }).fill(email);
    }
    if (phone) {
        await dialog.getByRole("textbox", { name: /Phone/ }).fill(phone);
    }
    await dialog.getByRole("button", { name: "Add Customer" }).last().click();

    return dialog;
}

/** The id of a customer by their email, so the afterEach can remove them. */
async function remember(page, email) {
    const props = await responseProps(await page.request.get(listUrl(`?search=${encodeURIComponent(email)}`)));
    const customer = props.items.data.find((c) => c.email === email);
    if (customer) {
        created.push(customer.id);
    }

    return customer;
}

const uniqueEmail = () => `e2e-cust-${Math.random().toString(36).slice(2, 8)}@techiko.test`;

test.afterEach(async ({ serverAs }) => {
    if (! created.length) {
        return;
    }
    const superUser = await serverAs("super");
    while (created.length) {
        await apiJson(superUser, "DELETE", `${customersPath}/${created.pop()}`).catch(() => {});
    }
});

test.describe("Customers list (admin)", () => {
    test.use({ account: "admin" });

    test("lists the organization's customers 15 to a page, newest first", async ({ page }) => {
        await openCustomers(page);

        const { items } = await pageProps(page);
        expect(items.meta.per_page).toBe(15);
        expect(items.meta.total).toBeGreaterThan(15);
        await expect(rows(page)).toHaveCount(15);
        await expect(page.locator(".ant-pagination")).toContainText(`of ${items.meta.total} items`);
    });

    test("page 2 asks the server for its second page", async ({ page }) => {
        await openCustomers(page);
        const { items } = await pageProps(page);

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        await expect(rows(page)).toHaveCount(Math.min(15, items.meta.total - 15));
    });

    test("a row shows the member's contact details, tier, points and spending", async ({ page }) => {
        const { silver } = members();
        await openCustomers(page);

        await search(page, silver.email);

        const row = rows(page).first();
        await expect(cell(row, "customer")).toContainText(silver.name);
        await expect(cell(row, "customer")).toContainText(silver.email);
        // The tier is written lowercase and capitalized by the stylesheet.
        await expect(cell(row, "loyalty")).toContainText(/silver/i);
        await expect(cell(row, "loyalty")).toContainText(silver.points.toLocaleString("en-US"));
        await expect(cell(row, "stats"), "spending is formatted").toContainText(peso(silver.spent));
        await expect(cell(row, "since")).toHaveText(/\w+ \d{1,2}, \d{4}/);
    });

    test("spending with centavos keeps them", async ({ page }) => {
        const { bronze } = members();
        await openCustomers(page);

        await search(page, bronze.email);

        await expect(cell(rows(page).first(), "stats")).toContainText(peso(bronze.spent));
    });

    test("a customer with no address says so", async ({ page }) => {
        const email = uniqueEmail();
        await openCustomers(page);
        await addCustomer(page, { name: "E2E No Contact", email });
        await expect(notice(page, "Customer Created")).toBeVisible();
        await remember(page, email);

        await search(page, email);

        await expect(cell(rows(page).first(), "contact")).toContainText("No address");
    });

    for (const [what, value] of [
        ["name", () => members().gold.name],
        ["email", () => members().gold.email],
    ]) {
        test(`search finds a customer by ${what}`, async ({ page }) => {
            await openCustomers(page);

            await search(page, value());

            await expect(rows(page)).toHaveCount(1);
            await expect(rows(page).first()).toContainText(members().gold.name);
        });
    }

    test("the search box shows the term the list is filtered by", async ({ page }) => {
        await openCustomers(page, listUrl(`?search=${encodeURIComponent(members().gold.name)}`));

        await expect(page.getByPlaceholder("Search customers by name, email, or phone...")).toHaveValue(
            members().gold.name,
        );
        await expect(rows(page)).toHaveCount(1);
    });

    test("the tier filter shows only that tier", async ({ page }) => {
        await openCustomers(page);

        await filterBy(page, "Tier", "Gold", "tier", "gold");

        await expect(rowWith(page, members().gold.name)).toHaveCount(1);
        await expect(rowWith(page, members().silver.name)).toHaveCount(0);
        for (const tier of await cell(rows(page), "loyalty").allInnerTexts()) {
            expect(tier.split("\n")[0].trim()).toBe("Gold");
        }
        await expect(page.locator(".ant-tag").filter({ hasText: "Tier" })).toContainText("Gold");
    });

    test("the loyalty status filter separates members holding points from those with none", async ({ page }) => {
        await openCustomers(page);

        await filterBy(page, "Loyalty Status", "Not Enrolled", "loyalty_status", "not_enrolled");

        await expect(rowWith(page, members().noPoints.name)).toHaveCount(1);
        await expect(rowWith(page, members().bronze.name), "holds 150 points").toHaveCount(0);
    });

    test("the registration period filter leaves out older registrations", async ({ page }) => {
        const { longStanding, gold } = members();
        await openCustomers(page);

        await filterBy(page, "Registration Period", "Last 7 days", "date_range", "7_days");

        // Counting rows would race the tests that add customers, so ask who is on each list.
        const recent = await responseProps(await page.request.get(listUrl("?date_range=7_days&per_page=100")));
        const everyone = await responseProps(await page.request.get(listUrl("?per_page=100")));
        const emails = (props) => props.items.data.map((c) => c.email);

        expect(emails(everyone), "registered two years ago").toContain(longStanding.email);
        expect(emails(recent), "and so left out of the last 7 days").not.toContain(longStanding.email);
        expect(emails(recent), "while this week's registrations stay").toContain(gold.email);
    });

    test("only this organization's customers are listed", async ({ page }) => {
        await openCustomers(page, listUrl("?per_page=100"));

        const { items } = await pageProps(page);
        expect([...new Set(items.data.map((c) => c.domain))]).toEqual(["jollibee-corp"]);
    });
});

test.describe("Adding and editing a customer (admin)", () => {
    test.use({ account: "admin" });
    // These add customers, whose email has to be free. One at a time, in order.
    test.describe.configure({ mode: "default" });

    test("a new customer is added to the list", async ({ page }) => {
        const email = uniqueEmail();
        const name = `E2E Added ${email.slice(9, 15)}`;
        await openCustomers(page);

        await addCustomer(page, { name, email, phone: "09170000123" });

        await expect(notice(page, "Customer Created")).toBeVisible();
        const customer = await remember(page, email);
        expect(customer.name).toBe(name);
        await search(page, email);
        await expect(rows(page).first()).toContainText(name);
        await expect(cell(rows(page).first(), "loyalty"), "starts on bronze with no points").toContainText(/bronze/i);
    });

    test("a customer needs a name", async ({ page }) => {
        await openCustomers(page);

        await page.getByRole("button", { name: "Add Customer" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Add New Customer" });
        await dialog.getByRole("textbox", { name: /Email/ }).fill(uniqueEmail());
        await dialog.getByRole("button", { name: "Add Customer" }).last().click();

        await expect(dialog.locator(".ant-form-item-explain-error").first()).toBeVisible();
        await expect(dialog).toBeVisible();
    });

    test("an email already used in this organization is refused", async ({ page }) => {
        await openCustomers(page);

        await addCustomer(page, { name: "E2E Duplicate Email", email: members().gold.email });

        await expect(notice(page, "already been taken")).toBeVisible();
    });

    test("editing a customer saves it and says so", async ({ page }) => {
        const email = uniqueEmail();
        await openCustomers(page);
        await addCustomer(page, { name: "E2E Before Edit", email });
        await expect(notice(page, "Customer Created")).toBeVisible();
        await remember(page, email);
        await search(page, email);

        await rows(page).first().getByRole("button", { name: "Edit Customer" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: /Customer/ }).last();
        await dialog.getByRole("textbox", { name: /Full Name/ }).fill("E2E After Edit");
        await dialog.getByRole("textbox", { name: /Phone/ }).fill("09171111222");
        await dialog.getByRole("button", { name: /Update|Save/ }).last().click();

        await expect(notice(page, "Customer Updated")).toBeVisible();
        await expect(dialog, "the dialog closes once it is saved").toBeHidden();
        await expect(rows(page).first()).toContainText("E2E After Edit");
        await expect(cell(rows(page).first(), "contact")).toContainText("09171111222");
    });

    test("a customer's details open in a dialog", async ({ page }) => {
        const { gold } = members();
        await openCustomers(page);
        await search(page, gold.email);

        await rows(page).first().getByRole("button", { name: "View Details" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Customer Details" });
        await expect(dialog).toContainText(gold.name);
        await expect(dialog).toContainText(gold.email);
        await expect(dialog).toContainText(peso(gold.spent));
        await expect(dialog).toContainText(gold.points.toLocaleString("en-US"));
    });
});

test.describe("Customers as a cashier", () => {
    test.use({ account: "cashier" });

    test("can look customers up and add one", async ({ page }) => {
        await openCustomers(page);

        await expect(rows(page).first()).toBeVisible();
        await expect(page.getByRole("button", { name: "Add Customer" })).toBeVisible();
        await expect(rows(page).first().getByRole("button", { name: "Edit Customer" })).toBeVisible();
    });
});

test.describe("Customers access (API)", () => {
    test("the page size is capped", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { items } = await responseProps(await api.get(listUrl("?per_page=100000")));

        expect(items.meta.per_page).toBeLessThanOrEqual(100);
    });

    for (const query of ["?per_page=0", "?per_page=abc", "?tier=bogus", "?loyalty_status=bogus", "?date_range=bogus", "?page=-1"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(listUrl(query))).status()).toBeLessThan(500);
        });
    }

    test("an email another organization already uses is free here", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const theirs = await serverAs("mcManager");
        const mcCustomer = (await responseProps(await theirs.get("/domains/mcdonalds-corp/customers"))).items.data[0];

        const res = await apiJson(api, "POST", customersPath, {
            name: "E2E Same Address",
            email: mcCustomer.email,
        });

        expect(res.status, "accepted for this organization").toBeLessThan(400);
        const mine = (await responseProps(await api.get(listUrl(`?search=${encodeURIComponent(mcCustomer.email)}`)))).items.data;
        expect(mine).toHaveLength(1);
        created.push(mine[0].id);
    });

    test("a customer of another organization can't be changed", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const theirs = await serverAs("mcManager");
        const mcCustomer = (await responseProps(await theirs.get("/domains/mcdonalds-corp/customers"))).items.data[0];

        const res = await apiJson(api, "PUT", `${customersPath}/${mcCustomer.id}`, { name: "E2E Hijacked" });

        expect(res.status).toBe(403);
        const after = (await responseProps(await theirs.get("/domains/mcdonalds-corp/customers"))).items.data
            .find((c) => c.id === mcCustomer.id);
        expect(after.name, "their customer is untouched").toBe(mcCustomer.name);
    });

    test("another organization's manager can't list Jollibee's customers", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", customersPath)).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(customersPath);
        await expect(page).toHaveURL(/\/login$/);
    });
});
