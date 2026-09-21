import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The credits page (Credit Management): who owes the organization money, how much of what they are
 * allowed they have used, and how much of it is late.
 *
 * The accounts come from database/seeders/E2ECreditSeeder.php, one in each state the page sorts
 * customers into — overdue, at their limit, in good standing, credit switched off — so the status
 * filter has something to include and something to leave out, plus one payable account per
 * Playwright worker, because recording a payment changes the balance it is recorded against.
 *
 * Customers are added and removed by other suites while this one runs, so nothing here counts rows
 * it did not seed: the figures are read back from the page's own props, and who is on a filtered
 * list is asked rather than counted.
 */

const fixture = () => fixtureIds().credit;
const creditsPath = "/domains/jollibee-corp/credits";
const listUrl = (query = "") => `${creditsPath}${query}`;
const customerUrl = (id, path = "") => `${creditsPath}/customers/${id}${path}`;

/** This worker's own account, so parallel payments don't fight over one balance. */
const payable = (parallelIndex) => fixture().payable[parallelIndex % fixture().payable.length];

const peso = (amount) =>
    `₱${Number(amount).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const cell = (row, name) =>
    row.locator("td").nth(["customer", "status", "limit", "balance", "available", "overdue"].indexOf(name));

async function openCredits(page, url = listUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await page.getByPlaceholder("Search customers by name, email, or phone...").fill(text);
    await found;
}

/** Picks a status in the filter popover and waits for the list it asks for. */
async function filterByStatus(page, option, value) {
    await page.locator("button:has(.anticon-filter)").click();
    const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
    const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("status") === value);
    await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Status" }), 0, option);
    await loaded;
}

/** The names on a filtered list, asked for rather than counted off the screen. */
async function namesOn(api, query = "") {
    const props = await responseProps(await api.get(listUrl(`${query}${query ? "&" : "?"}per_page=100`)));

    return props.customers.data.map((c) => c.name);
}

/** A credit account as the server has it now, found by the address the search does cover. */
async function accountNow(api, account) {
    const props = await responseProps(
        await api.get(listUrl(`?search=${encodeURIComponent(account.email)}&per_page=100`)),
    );
    const found = props.customers.data.find((c) => c.id === account.id);
    expect(found, `${account.name} is listed`).toBeTruthy();

    return found;
}

test.describe("Credit accounts (admin)", () => {
    test.use({ account: "admin" });

    test("lists the organization's customers 15 to a page, with the real total", async ({ page }) => {
        await openCredits(page);

        const { customers } = await pageProps(page);
        expect(customers.meta.per_page, "the page size the server used").toBe(15);
        expect(customers.meta.total, "more than one page of them").toBeGreaterThan(15);
        await expect(rows(page)).toHaveCount(15);
        // The table used to be handed a paginator it could not read, and fell back to paging the
        // fifteen rows it had ten at a time — so it said "15 items" however many there were.
        await expect(page.locator(".ant-pagination")).toContainText(`of ${customers.meta.total} items`);
    });

    test("page 2 asks the server for its second page", async ({ page }) => {
        await openCredits(page);
        const { customers } = await pageProps(page);
        const firstPage = await rows(page).first().innerText();

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        // The second page is the organization's next customers, not the same ones counted again.
        await expect(rows(page).first()).not.toHaveText(firstPage);
        const second = await responseProps(await page.request.get(listUrl("?page=2")));
        expect(second.customers.meta.current_page).toBe(2);
        expect(
            second.customers.data.map((c) => c.id),
            "different customers from the first",
        ).not.toEqual(customers.data.map((c) => c.id));
    });

    test("a row shows what they are allowed, owe, have left and owe late", async ({ page }) => {
        const { overdue } = fixture();
        await openCredits(page);

        await search(page, overdue.name);

        const row = rows(page).first();
        await expect(cell(row, "customer")).toContainText(overdue.name);
        await expect(cell(row, "customer")).toContainText(overdue.email);
        await expect(cell(row, "limit")).toHaveText(peso(overdue.limit));
        await expect(cell(row, "balance")).toHaveText(peso(overdue.owed));
        await expect(cell(row, "available"), "the limit less what is owed").toHaveText(peso(overdue.available));
        await expect(cell(row, "overdue"), "all of it is late").toHaveText(peso(overdue.owed));
    });

    for (const [state, key, tag] of [
        ["owes money past its due date", "overdue", "Overdue"],
        ["owes everything it is allowed", "atLimit", "At Limit"],
        ["owes money that isn't due yet", "goodStanding", "Good Standing"],
        ["has no credit at all", "disabled", "Disabled"],
    ]) {
        test(`an account that ${state} is marked ${tag}`, async ({ page }) => {
            const account = fixture()[key];
            await openCredits(page);

            await search(page, account.name);

            await expect(cell(rows(page).first(), "status")).toHaveText(tag);
        });
    }

    test("an account with nothing late shows no overdue amount", async ({ page }) => {
        const { goodStanding } = fixture();
        await openCredits(page);

        await search(page, goodStanding.name);

        await expect(cell(rows(page).first(), "overdue")).toHaveText(peso(0));
    });

    for (const [what, pick] of [
        ["name", (a) => a.name],
        ["email", (a) => a.email],
    ]) {
        test(`search finds an account by ${what}`, async ({ page }) => {
            const { goodStanding } = fixture();
            await openCredits(page);

            await search(page, pick(goodStanding));

            await expect(rows(page)).toHaveCount(1);
            await expect(rows(page).first()).toContainText(goodStanding.name);
        });
    }

    test("the search box shows the term the list is filtered by", async ({ page }) => {
        const { atLimit } = fixture();
        await openCredits(page, listUrl(`?search=${encodeURIComponent(atLimit.name)}`));

        await expect(page.getByPlaceholder("Search customers by name, email, or phone...")).toHaveValue(
            atLimit.name,
        );
        await expect(rows(page)).toHaveCount(1);
    });

    test("the status filter asks the server and marks what it filtered by", async ({ page }) => {
        const { overdue, goodStanding } = fixture();
        await openCredits(page);

        await filterByStatus(page, "Overdue", "overdue");

        await expect(rowWith(page, overdue.name)).toHaveCount(1);
        await expect(rowWith(page, goodStanding.name), "nothing late about this one").toHaveCount(0);
        await expect(page.locator(".ant-tag").filter({ hasText: "Status" })).toContainText("Overdue");
    });

    for (const [option, value, included, excluded] of [
        ["overdue", "overdue", "overdue", "goodStanding"],
        ["at_limit", "at_limit", "atLimit", "goodStanding"],
        ["good_standing", "good_standing", "goodStanding", "overdue"],
        ["disabled", "disabled", "disabled", "overdue"],
        ["enabled", "enabled", "overdue", "disabled"],
    ]) {
        test(`"${option}" keeps the right accounts and leaves out the others`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            const names = await namesOn(api, `?status=${value}`);

            expect(names, `${included} belongs here`).toContain(fixture()[included].name);
            expect(names, `${excluded} does not`).not.toContain(fixture()[excluded].name);
        });
    }

    test("the overdue banner counts the accounts that are late and what they owe", async ({ page }) => {
        const { overdue } = fixture();
        await openCredits(page);

        const banner = page.locator(".ant-alert").filter({ hasText: "Overdue Account" });
        await expect(banner).toBeVisible();
        const summary = await apiJson(page.request, "GET", `${creditsPath}/overdue`);
        expect(summary.status).toBe(200);

        const total = summary.body.data.reduce((sum, a) => sum + Number(a.overdue_amount), 0);
        await expect(banner).toContainText(`${summary.body.data.length} Overdue Account`);
        await expect(banner).toContainText(peso(total));
        expect(
            summary.body.data.map((a) => a.customer.name),
            "our late account is among them",
        ).toContain(overdue.name);
    });

    test("only this organization's customers are listed", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { other } = fixture();

        const props = await responseProps(await api.get(listUrl("?per_page=100")));

        expect([...new Set(props.customers.data.map((c) => c.domain))]).toEqual(["jollibee-corp"]);
        expect(props.customers.data.map((c) => c.name)).not.toContain(other.name);
    });
});

test.describe("Changing an account's credit (admin)", () => {
    test.use({ account: "admin" });

    test("the settings dialog opens on what the account is allowed now", async ({ page }, testInfo) => {
        const account = payable(testInfo.parallelIndex);
        await openCredits(page);
        await search(page, account.name);

        await rows(page).first().getByRole("button", { name: "Edit Credit Limit" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Credit Settings" });
        await expect(dialog).toBeVisible();
        const limit = await dialog.locator(".ant-input-number-input").first().inputValue();
        expect(Number(limit.replace(/,/g, "")), "the limit it has now").toBeGreaterThan(0);
        await expect(dialog.locator(".ant-checkbox-input")).toBeChecked();
    });

    test("a new limit is saved and the row shows it", async ({ page, serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const account = payable(testInfo.parallelIndex);
        const before = await accountNow(api, account);
        const raised = Number(before.credit_limit) + 250;

        await openCredits(page);
        await search(page, account.name);
        await rows(page).first().getByRole("button", { name: "Edit Credit Limit" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Credit Settings" });
        await dialog.locator(".ant-input-number-input").first().fill(String(raised));
        await dialog.getByRole("button", { name: "OK" }).click();

        await expect(dialog).toBeHidden();
        await expect.poll(async () => (await accountNow(api, account)).credit_limit).toBe(raised);

        // Saving reloads the list behind the dialog, keeping the search that is already in the box,
        // so the row comes back by itself — typing the same search again would change nothing and
        // ask for nothing.
        await expect(cell(rows(page).first(), "limit")).toHaveText(peso(raised));
        await expect(
            cell(rows(page).first(), "available"),
            "and what is left of it goes up by the same",
        ).toHaveText(peso(raised - Number(before.credit_balance)));

        // Put it back, so the next test on this worker starts from the seeded figure.
        await apiJson(api, "PUT", customerUrl(account.id, "/settings"), {
            credit_limit: Number(before.credit_limit),
            credit_terms_days: before.credit_terms_days,
            credit_enabled: before.credit_enabled,
        });
    });

    test("a credit limit can't be negative, and the terms have to be a real number of days", async ({ serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const account = payable(testInfo.parallelIndex);

        const res = await apiJson(api, "PUT", customerUrl(account.id, "/settings"), {
            credit_limit: -100,
            credit_terms_days: 0,
        });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors ?? {}).sort()).toEqual(["credit_limit", "credit_terms_days"]);
    });
});

test.describe("Recording a payment (admin)", () => {
    test.use({ account: "admin" });

    test("a payment reduces what the customer owes", async ({ page, serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const account = payable(testInfo.parallelIndex);
        const before = await accountNow(api, account);
        const paying = 100;

        await openCredits(page);
        await search(page, account.name);
        await rows(page).first().getByRole("button", { name: "Record Payment" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Record Payment" });
        await expect(dialog).toBeVisible();
        await expect(dialog, "the balance it is being taken off").toContainText(
            peso(Number(before.credit_balance)),
        );
        await dialog.locator(".ant-input-number-input").first().fill(String(paying));
        await dialog.getByRole("button", { name: "OK" }).click();

        await expect(dialog).toBeHidden();
        await expect
            .poll(async () => Number((await accountNow(api, account)).credit_balance))
            .toBe(Number(before.credit_balance) - paying);

        // Put the balance back, so the next test on this worker starts from the seeded figure.
        await apiJson(api, "POST", customerUrl(account.id, "/transactions"), {
            transaction_type: "adjustment",
            amount: paying,
            notes: "E2E restored",
        });
    });

    test("the dialog lists the invoice the money is owed on", async ({ page, serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const account = payable(testInfo.parallelIndex);

        const outstanding = await apiJson(api, "GET", customerUrl(account.id, "/outstanding-invoices"));
        expect(outstanding.status).toBe(200);
        expect(outstanding.body.outstanding_invoices.length, "an invoice to settle").toBeGreaterThan(0);

        await openCredits(page);
        await search(page, account.name);
        await rows(page).first().getByRole("button", { name: "Record Payment" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Record Payment" });
        await expect(dialog.locator(".ant-checkbox-wrapper").first(), "with a box to apply it to").toBeVisible();
    });

    test("more than is owed is refused", async ({ serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const account = payable(testInfo.parallelIndex);
        const before = await accountNow(api, account);

        const res = await apiJson(api, "POST", customerUrl(account.id, "/transactions"), {
            transaction_type: "payment",
            amount: Number(before.credit_balance) + 1000,
        });

        expect(res.status).toBe(422);
        expect(res.body.message).toMatch(/cannot exceed/i);
        expect(
            Number((await accountNow(api, account)).credit_balance),
            "and nothing was taken off",
        ).toBe(Number(before.credit_balance));
    });

    test("a payment needs an amount above nothing", async ({ serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const account = payable(testInfo.parallelIndex);

        const res = await apiJson(api, "POST", customerUrl(account.id, "/transactions"), {
            transaction_type: "payment",
            amount: 0,
        });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors ?? {})).toContain("amount");
    });
});

test.describe("An account's credit details (admin)", () => {
    test.use({ account: "admin" });

    test("View opens the customer's own credit page", async ({ page }) => {
        const { overdue } = fixture();
        await openCredits(page);
        await search(page, overdue.name);

        await rows(page).first().getByRole("button", { name: "View Credit Details" }).click();

        await expect(page).toHaveURL(new RegExp(`/credits/customers/${overdue.id}$`));
        await expect(page.getByText(overdue.name).first()).toBeVisible();

        // Inertia moves between pages without reloading, so the props of the page now on screen
        // have to be asked for rather than read off the document the browser first loaded.
        const { customer, overdueAmount, availableCredit } = await responseProps(
            await page.request.get(customerUrl(overdue.id)),
        );
        expect(customer.id).toBe(overdue.id);
        expect(Number(overdueAmount), "what they owe late").toBe(overdue.owed);
        expect(Number(availableCredit), "and what is left of their limit").toBe(overdue.available);
    });

    test("the history endpoint gives the account's transactions", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { overdue } = fixture();

        const res = await apiJson(api, "GET", customerUrl(overdue.id, "/history"));

        expect(res.status).toBe(200);
        expect(res.body.data.data.length, "the invoice they owe on").toBeGreaterThan(0);
        expect(res.body.data.data.every((t) => t.customer_id === overdue.id)).toBe(true);
    });
});

test.describe("Credits as a cashier", () => {
    test.use({ account: "cashier" });

    test("can read the accounts without being refused anything", async ({ page }) => {
        const { overdue } = fixture();
        await openCredits(page);

        await expect(rows(page).first()).toBeVisible();
        await search(page, overdue.name);
        await expect(cell(rows(page).first(), "balance")).toHaveText(peso(overdue.owed));
        // A cashier may not read the overdue summary, so the page must not go asking for it.
        await expect(page.locator(".ant-alert").filter({ hasText: "Overdue Account" })).toHaveCount(0);
    });
});

test.describe("Credits access (API)", () => {
    test("a cashier can't record a payment", async ({ serverAs }) => {
        const api = await serverAs("cashier");
        const { goodStanding } = fixture();

        const res = await apiJson(api, "POST", customerUrl(goodStanding.id, "/transactions"), {
            transaction_type: "payment",
            amount: 10,
        });

        expect(res.status).toBe(403);
    });

    test("a cashier can't change what an account is allowed", async ({ serverAs }) => {
        const api = await serverAs("cashier");
        const { goodStanding } = fixture();

        const res = await apiJson(api, "PUT", customerUrl(goodStanding.id, "/settings"), {
            credit_limit: 999999,
            credit_terms_days: 30,
        });

        expect(res.status).toBe(403);
        const still = await accountNow(await serverAs("admin"), goodStanding);
        expect(Number(still.credit_limit), "the limit is untouched").toBe(goodStanding.limit);
    });

    test("another organization's manager can't read Jollibee's credit accounts", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", creditsPath)).status).toBe(403);
    });

    test("a customer of another organization can't be reached through this one", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { other } = fixture();

        expect((await apiJson(api, "GET", customerUrl(other.id))).status, "their details").toBe(403);
        expect(
            (await apiJson(api, "GET", customerUrl(other.id, "/outstanding-invoices"))).status,
            "their invoices",
        ).toBe(403);

        const res = await apiJson(api, "POST", customerUrl(other.id, "/transactions"), {
            transaction_type: "payment",
            amount: 10,
        });
        expect(res.status, "and no payment against them").toBe(403);
    });

    test("the page size is capped", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const props = await responseProps(await api.get(listUrl("?per_page=100000")));

        expect(props.customers.meta.per_page).toBeLessThanOrEqual(100);
    });

    for (const query of ["?per_page=0", "?per_page=abc", "?status=bogus", "?page=-1", "?search=%25"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(listUrl(query))).status()).toBeLessThan(500);
        });
    }

    test("guests are sent to login", async ({ page }) => {
        await page.goto(creditsPath);

        await expect(page).toHaveURL(/\/login$/);
    });
});
