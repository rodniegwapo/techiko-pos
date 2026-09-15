import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

const fixture = () => fixtureIds().inventoryDashboard;
const moves = () => fixture().movements;
const movementsPath = "/domains/jollibee-corp/inventory/movements";
const storeUrl = (query = "") => `${movementsPath}?location_id=${fixture().store.id}${query}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });

async function openMovements(page, url = storeUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const loaded = page.waitForResponse((r) => {
        const url = new URL(r.url());
        return url.pathname === movementsPath && url.searchParams.get("search") === text;
    });
    await page.getByPlaceholder("Search movements, products, or references...").fill(text);
    await loaded;
}

async function openDetails(page, rowText) {
    await rowWith(page, rowText).first().getByRole("button", { name: "View Details" }).click();
    const dialog = page.getByRole("dialog").filter({ hasText: "Movement Details" });
    await expect(dialog).toBeVisible();
    return dialog;
}

test.describe("Inventory movements list (admin)", () => {
    test.use({ account: "admin" });

    test("lists the store's movements newest first, 50 per page", async ({ page }) => {
        await openMovements(page);

        await expect(rows(page)).toHaveCount(50);
        await expect(rows(page).first()).toContainText(moves().adjustment.product);
        await expect(rows(page).nth(1)).toContainText(moves().sale.product);
    });

    test("movement types show readable labels", async ({ page }) => {
        await openMovements(page);

        await expect(rowWith(page, moves().purchase.batch)).toContainText("Purchase");
        await expect(rows(page).first(), "adjustment").toContainText("Stock Adjustment");
        await expect(rowWith(page, moves().transferIn.product), "transfer in").toContainText("Transfer In");
        await expect(rows(page).filter({ hasText: "transfer_in" })).toHaveCount(0);
    });

    test("the Date & Time column shows the time", async ({ page }) => {
        await openMovements(page);

        await expect(rows(page).first().locator("td").first()).toHaveText(/\d{1,2}:\d{2}/);
    });

    test("quantities show their sign", async ({ page }) => {
        await openMovements(page);

        await expect(rowWith(page, moves().purchase.batch)).toContainText("+20");
        await expect(rows(page).first()).toContainText("-5");
    });

    test("a stock decrease shows a down arrow, even for an adjustment", async ({ page }) => {
        await openMovements(page);

        // The icons' own class names are replaced by the `class` passed to them, so match the arrowheads' paths.
        const arrowDown = 'svg:has(path[d="M18 13l-6 6"])';
        const arrowUp = 'svg:has(path[d="M18 11l-6 -6"])';
        await expect(rows(page).first().locator(`.ant-tag ${arrowDown}`), "-5 adjustment").toHaveCount(1);
        await expect(rowWith(page, moves().purchase.batch).locator(`.ant-tag ${arrowUp}`), "+20 purchase").toHaveCount(1);
    });

    test("shows the product's SKU and the batch number", async ({ page }) => {
        await openMovements(page);

        const row = rowWith(page, moves().purchase.batch);
        await expect(row).toContainText(moves().purchase.product);
        await expect(row).toContainText(`SKU: ${moves().purchase.sku}`);
        await expect(row).toContainText(`Batch: ${moves().purchase.batch}`);
    });

    for (const [what, text, expected] of [
        ["product name", () => moves().adjustment.product, () => moves().adjustment.product],
        ["batch number", () => moves().purchase.batch, () => moves().purchase.batch],
        ["reason", () => moves().damage.reason, () => moves().damage.product],
    ]) {
        test(`search finds a movement by ${what}`, async ({ page }) => {
            await openMovements(page);

            await search(page, text());

            await expect(rows(page)).toHaveCount(1);
            await expect(rows(page).first()).toContainText(expected());
        });
    }

    test("the movement type filter keeps the store", async ({ page }) => {
        await openMovements(page);

        await page.locator("button:has(.anticon-filter)").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("movement_type") === "damage");
        await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Movement Type" }), 0, "Damaged Goods");
        const res = await loaded;

        expect(new URL(res.url()).searchParams.get("location_id"), "store kept").toBe(String(fixture().store.id));
        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText("Damaged Goods");
    });

    test("page 2 shows the rest of the store's movements", async ({ page }) => {
        await openMovements(page);

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        const res = await loaded;

        expect(new URL(res.url()).searchParams.get("location_id"), "store kept").toBe(String(fixture().store.id));
        await expect(rows(page)).toHaveCount(moves().total - 50);
    });

    test("the empty store warning shows for a store that isn't in this organization", async ({ page }) => {
        await openMovements(page, `${movementsPath}?location_id=${fixture().otherOrgLocationId}`);

        await expect(page.getByText("Select a store")).toBeVisible();
        await expect(rows(page)).toHaveCount(0);
    });

    test("Export downloads the movements", async ({ page }) => {
        await openMovements(page);

        const downloading = page.waitForEvent("download", { timeout: 10_000 }).catch(() => null);
        await page.getByRole("button", { name: "Export" }).click();
        const download = await downloading;

        expect(download, "clicking Export should download a file").not.toBeNull();
        expect(download.suggestedFilename()).toMatch(/\.csv$/);
        const { readFile } = await import("node:fs/promises");
        const lines = (await readFile(await download.path(), "utf8")).trim().split(/\r?\n/);
        expect(lines[0]).toContain("Product");
        expect(lines, "one line per movement plus the header").toHaveLength(moves().total + 1);
        expect(lines.find((l) => l.includes(moves().purchase.batch))).toContain("Purchase");
    });

    test("Export only includes the movements matching the search", async ({ page }) => {
        await openMovements(page);
        await search(page, moves().damage.reason);

        const downloading = page.waitForEvent("download", { timeout: 10_000 });
        await page.getByRole("button", { name: "Export" }).click();
        const download = await downloading;

        const { readFile } = await import("node:fs/promises");
        const lines = (await readFile(await download.path(), "utf8")).trim().split(/\r?\n/);
        expect(lines).toHaveLength(2);
        expect(lines[1]).toContain("Damaged Goods");
    });
});

test.describe("Movement details (admin)", () => {
    test.use({ account: "admin" });

    test("shows the product, quantities, location, cost, batch, user and notes", async ({ page }) => {
        const m = moves().purchase;
        await openMovements(page);

        const dialog = await openDetails(page, m.batch);

        await expect(dialog.getByRole("heading", { name: "Purchase" })).toBeVisible();
        await expect(dialog).toContainText(`Movement #${m.id}`);
        await expect(dialog).toContainText(m.product);
        await expect(dialog).toContainText(m.sku);
        for (const [label, value] of [["Before", "30"], ["Change", "+20"], ["After", "50"]]) {
            await expect(dialog.locator("div.rounded-lg.text-center").filter({ hasText: label })).toContainText(value);
        }
        await expect(dialog).toContainText(fixture().store.name);
        await expect(dialog).toContainText("₱10.00");
        await expect(dialog).toContainText("₱200.00");
        await expect(dialog).toContainText(m.batch);
        await expect(dialog).toContainText("June 30, 2027");
        await expect(dialog).toContainText("Jollibee Admin");
        await expect(dialog).toContainText(m.reason);
        await expect(dialog).toContainText(m.notes);
    });

    test("shows the product's category", async ({ page }) => {
        const m = moves().purchase;
        await openMovements(page);

        const dialog = await openDetails(page, m.batch);

        await expect(dialog.locator("div").filter({ has: page.getByText("Category", { exact: true }) }).last()).toContainText(m.category);
    });

    test("shows what a sale movement refers to", async ({ page }) => {
        const m = moves().sale;
        await openMovements(page);

        const dialog = await openDetails(page, m.product);

        await expect(dialog).toContainText("Reference Information");
        await expect(dialog).toContainText("Sale");
        await expect(dialog).toContainText(`#${m.referenceId}`);
    });

    test("the close button closes the dialog", async ({ page }) => {
        await openMovements(page);
        const dialog = await openDetails(page, moves().purchase.batch);

        await dialog.getByRole("button", { name: "Close" }).click();

        await expect(dialog).toBeHidden();
    });

    test("movement data doesn't include the whole user account", async ({ page }) => {
        await openMovements(page);

        const { movements } = await pageProps(page);
        const user = movements.data.find((m) => m.user)?.user;

        expect(user, "a movement with a user").toBeTruthy();
        expect(Object.keys(user).sort(), "user fields sent to the browser").toEqual(["id", "name"]);
    });
});

test.describe("Inventory movements access and filters (API)", () => {
    test("date filters limit the movements to those days", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { movements } = await responseProps(await api.get(storeUrl("&date_from=2026-03-10&date_to=2026-03-11")));

        expect(movements.data.map((m) => m.id).sort()).toEqual([moves().purchase.id, moves().sale.id].sort());
    });

    test("the page size is capped", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { movements } = await responseProps(await api.get(storeUrl("&per_page=100000")));

        expect(movements.meta.per_page).toBeLessThanOrEqual(100);
    });

    for (const query of ["&per_page=0", "&per_page=abc", "&date_from=not-a-date", "&movement_type=bogus", "&page=-1"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(storeUrl(query))).status()).toBeLessThan(500);
        });
    }

    test("a manager only sees their own store's movements", async ({ serverAs }) => {
        const api = await serverAs("manager");

        const props = await responseProps(await api.get(storeUrl()));

        expect(props.currentLocation.code).toBe(fixture().mainLocation.code);
        expect(props.movements.data.every((m) => m.location_id === fixture().mainLocation.id)).toBe(true);
    });

    test("a cashier can't open movements", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        expect((await apiJson(api, "GET", storeUrl())).status).toBe(403);
    });

    test("another organization's manager can't open Jollibee movements", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", storeUrl())).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(storeUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
