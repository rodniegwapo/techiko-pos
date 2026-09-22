import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The void logs page (Void Logs): every line a cashier struck off a sale, who approved it and why.
 *
 * The fixtures come from database/seeders/E2EVoidLogSeeder.php: three voids for this organization,
 * one of them dated a year back so the date filter has something to leave out, and one for another
 * organization so the two can be told apart. Each is against its own product, so a search matches
 * exactly one of them and not whatever the sales suite is voiding alongside — that suite adds voids
 * of its own while this one runs, so nothing here counts rows it did not put there.
 */

const fixture = () => fixtureIds().voidLogs;
const voidLogsPath = "/domains/jollibee-corp/void-logs";
const listUrl = (query = "") => `${voidLogsPath}${query}`;

const peso = (amount) => `₱${Number(amount).toLocaleString("en-US", { minimumFractionDigits: 2 })}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const cell = (row, name) =>
    row.locator("td").nth(["user", "product", "approver", "amount", "when"].indexOf(name));

async function openVoidLogs(page, url = listUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await page.getByPlaceholder("Input search text").fill(text);
    await found;
}

/**
 * The voids the organization's page lists for a search, as product names.
 *
 * Narrowed by the search rather than read page by page: the sales suite is voiding lines of its own
 * while this one runs, and a list being added to underneath shifts rows between one page and the
 * next, so walking it would miss what it was looking for.
 */
async function voidsFor(api, search, query = "") {
    const props = await responseProps(
        await api.get(listUrl(`?search=${encodeURIComponent(search)}&per_page=100${query}`)),
    );

    return props.items.data.map((v) => v.sale_item?.product?.name);
}

/** The first page of the whole list, wide enough to say something about the organization's voids. */
async function someVoids(api) {
    return (await responseProps(await api.get(listUrl("?per_page=100")))).items.data;
}

test.describe("Void logs (admin)", () => {
    test.use({ account: "admin" });

    test("lists the organization's voids, newest first", async ({ page }) => {
        await openVoidLogs(page);

        const { items } = await pageProps(page);
        expect(items.meta.per_page).toBe(10);
        await expect(rows(page)).toHaveCount(Math.min(10, items.meta.total));

        const when = items.data.map((v) => Date.parse(v.created_at));
        expect(when, "newest first").toEqual([...when].sort((a, b) => b - a));
    });

    test("a row names the cashier, the line, who approved it and what it came to", async ({ page }) => {
        const voided = fixture().recent[0];
        await openVoidLogs(page);

        await search(page, voided.product);

        const row = rows(page).first();
        await expect(cell(row, "user")).toHaveText(voided.cashier);
        await expect(cell(row, "product")).toHaveText(voided.product);
        await expect(cell(row, "approver")).toHaveText(voided.approver);
        await expect(cell(row, "amount"), "the amount is formatted").toHaveText(peso(voided.amount));
        await expect(cell(row, "when")).toHaveText(/\w+, \w+ \d{1,2}, \d{4} \d{2}:\d{2}:\d{2}/);
    });

    test("the details panel gives the reason the line was struck off", async ({ page }) => {
        const voided = fixture().recent[0];
        await openVoidLogs(page);
        await search(page, voided.product);

        await rows(page).first().getByRole("button", { name: "View Details" }).click();

        // The reason is the substance of a void log and the list has no column for it.
        const dialog = page.getByRole("dialog").filter({ hasText: "Void log details" });
        await expect(dialog).toContainText(voided.reason);
        await expect(dialog).toContainText(voided.product);
        await expect(dialog).toContainText(voided.cashier);
        await expect(dialog).toContainText(voided.approver);
        await expect(dialog).toContainText(peso(voided.amount));
        await expect(dialog, "and the sale it came from").toContainText(voided.invoice_number);
    });

    for (const [what, pick] of [
        ["the product", (v) => v.product],
        ["the cashier who voided it", (v) => v.cashier],
        ["the amount", (v) => String(v.amount)],
    ]) {
        test(`search finds a void by ${what}`, async ({ page }) => {
            const voided = fixture().recent[0];
            await openVoidLogs(page);

            await search(page, pick(voided));

            await expect(rowWith(page, voided.product).first()).toBeVisible();
        });
    }

    test("the search box shows the term the list is filtered by", async ({ page }) => {
        const voided = fixture().recent[1];
        await openVoidLogs(page, listUrl(`?search=${encodeURIComponent(voided.product)}`));

        await expect(page.getByPlaceholder("Input search text")).toHaveValue(voided.product);
        await expect(rows(page)).toHaveCount(1);
    });

    test("a search that matches nothing says so", async ({ page }) => {
        await openVoidLogs(page);

        await search(page, "E2E Nothing Was Ever Voided Like This");

        await expect(page.getByText("No void logs found")).toBeVisible();
        await expect(rows(page)).toHaveCount(0);
    });

    test("the date filter leaves out what falls outside it", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { old } = fixture();
        const recent = fixture().recent[0];
        const thisWeek = `&start_date=${today(-6)}&end_date=${today(0)}`;

        expect(await voidsFor(api, old.product), "voided a year ago").toContain(old.product);
        expect(await voidsFor(api, old.product, thisWeek), "and so left out of this week")
            .not.toContain(old.product);
        expect(await voidsFor(api, recent.product, thisWeek), "while this week's voids stay")
            .toContain(recent.product);
    });

    test("picking a range in the filter asks the server for it", async ({ page }) => {
        await openVoidLogs(page);

        const asked = page.waitForResponse((r) => new URL(r.url()).searchParams.has("start_date"));
        await page.locator("button:has(.anticon-filter)").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
        await popover.locator(".ant-picker-input input").first().click();
        await page.locator(".ant-picker-cell-today").first().click();
        await page.locator(".ant-picker-cell-today").first().click();
        await asked;

        await expect(page.locator(".ant-tag").filter({ hasText: "Select Date" })).toBeVisible();
    });

    test("another organization's voids are not on the list", async ({ page, serverAs }) => {
        const api = await serverAs("admin");
        const { other } = fixture();

        const voids = await someVoids(api);

        expect([...new Set(voids.map((v) => v.domain))], "one organization only").toEqual([
            "jollibee-corp",
        ]);
        expect(await voidsFor(api, other.product), "the other organization's void is not here")
            .not.toContain(other.product);
        await openVoidLogs(page, listUrl(`?search=${encodeURIComponent(other.product)}`));
        await expect(rows(page), "and cannot be searched out either").toHaveCount(0);
    });

    test("the Domain column is kept for the page that spans organizations", async ({ page }) => {
        await openVoidLogs(page);

        expect((await pageProps(page)).isGlobalView, "this page is one organization's").toBe(false);
        await expect(page.locator(".ant-table-thead th")).toHaveText([
            "User",
            "Product",
            "Approved By",
            "Amount",
            "Transaction Date",
            "Actions",
        ]);
    });

    test("page 2 asks the server for its second page", async ({ page }) => {
        await openVoidLogs(page);
        const { items } = await pageProps(page);
        test.skip(items.meta.last_page < 2, "not enough voids to page through");

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        await expect(rows(page).first()).toBeVisible();
        expect(new URL(page.url()).searchParams.get("page")).toBe("2");
    });
});

test.describe("Void logs as a super user", () => {
    test.use({ account: "super" });

    test("the organization's page still shows only its own voids", async ({ page }) => {
        const { other } = fixture();
        await openVoidLogs(page);

        const { items, isGlobalView } = await pageProps(page);
        expect(isGlobalView).toBe(false);
        expect(items.data.map((v) => v.domain).every((d) => d === "jollibee-corp")).toBe(true);
        await expect(rowWith(page, other.product), "not the other organization's").toHaveCount(0);
    });

    test("the page that spans organizations shows them side by side", async ({ page }) => {
        const { other } = fixture();
        const mine = fixture().recent[0];

        await page.goto("/void-logs");
        await expect(page.locator(".ant-table")).toBeVisible();

        expect((await pageProps(page)).isGlobalView, "this one is everybody's").toBe(true);
        await expect(page.locator(".ant-table-thead th"), "so it names the organization").toContainText([
            "Domain",
        ]);

        await search(page, other.product);
        await expect(rowWith(page, other.product), "another organization's void is reachable here").toHaveCount(1);
        await expect(rows(page).first()).toContainText("mcdonalds-corp");

        await search(page, mine.product);
        await expect(rows(page).first()).toContainText("jollibee-corp");
    });
});

test.describe("Void logs access (API)", () => {
    test("a cashier can't read the void log", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        expect((await apiJson(api, "GET", voidLogsPath)).status).toBe(403);
    });

    test("another organization's manager can't read Jollibee's void log", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", voidLogsPath)).status).toBe(403);
    });

    test("another organization sees only its own voids on its own page", async ({ serverAs }) => {
        const api = await serverAs("mcManager");
        const { other } = fixture();
        const mine = fixture().recent[0];

        const props = await responseProps(await api.get("/domains/mcdonalds-corp/void-logs?per_page=100"));
        const products = props.items.data.map((v) => v.sale_item?.product?.name);

        expect(products, "their own void").toContain(other.product);
        expect(products, "and none of ours").not.toContain(mine.product);
    });

    test("the page size is capped", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { items } = await responseProps(await api.get(listUrl("?per_page=100000")));

        expect(items.meta.per_page).toBeLessThanOrEqual(100);
    });

    for (const query of ["?per_page=0", "?per_page=abc", "?page=-1", "?start_date=bogus", "?search=%25"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(listUrl(query))).status()).toBeLessThan(500);
        });
    }

    test("guests are sent to login", async ({ page }) => {
        await page.goto(voidLogsPath);

        await expect(page).toHaveURL(/\/login$/);
    });
});

/** A date `days` from today, as the filter sends it. */
function today(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
}
