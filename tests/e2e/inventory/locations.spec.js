import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption, notice } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The inventory locations page (Inventory > Locations).
 *
 * Read-only expectations lean on stores that already exist: JB-MAIN (the organization's default),
 * JB-WH (its only warehouse) and the inactive E2E Inventory Store from E2EInventorySeeder, which
 * holds stock and so may not be deleted. Tests that deactivate or delete a store create their own
 * first and drop it again afterwards, so no other suite's store is ever touched.
 *
 * Not covered: making another store the organization's default, because that is shared state the
 * store-switching suite asserts on at the same time; its API side lives in store-switching.spec.js.
 */

const fixture = () => fixtureIds().inventoryDashboard;
const locationsPath = "/domains/jollibee-corp/inventory/locations";
const listUrl = (query = "") => `${locationsPath}${query}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const cell = (row, name) =>
    row.locator("td").nth(["location", "type", "address", "contact", "products", "status"].indexOf(name));

/** Ids of the locations this test created; dropped again in afterEach. */
const created = [];

async function openLocations(page, url = listUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const loaded = page.waitForResponse((r) => {
        const url = new URL(r.url());
        return url.pathname === locationsPath && url.searchParams.get("search") === text;
    });
    await page.getByPlaceholder("Search locations...").fill(text);
    await loaded;
}

/** The row's "More Actions" menu, open. */
async function openRowMenu(page, row) {
    await row.getByRole("button", { name: "More Actions" }).click();
    const menu = page.locator(".ant-dropdown:not(.ant-dropdown-hidden)");
    await expect(menu).toBeVisible();
    return menu;
}

const menuItem = (menu, name) => menu.locator("li", { hasText: name });

/** A location of this organization, created the way the Create page does it. */
async function createLocation(page, attrs = {}) {
    const code = `E2E-${Math.random().toString(36).slice(2, 7).toUpperCase()}`;
    const res = await apiJson(page.request, "POST", locationsPath, {
        name: `E2E Loc ${code}`,
        code,
        type: "store",
        is_active: true,
        ...attrs,
    });
    expect([200, 201, 302], `create location ${code}`).toContain(res.status);

    const props = await responseProps(await page.request.get(listUrl(`?search=${code}`)));
    const location = props.locations.data.find((l) => l.code === code);
    expect(location, `created location ${code}`).toBeTruthy();
    created.push(location.id);

    return location;
}

test.afterEach(async ({ page }) => {
    while (created.length) {
        // Tolerant: a test may already have deleted its own location.
        await apiJson(page.request, "DELETE", `${locationsPath}/${created.pop()}`).catch(() => {});
    }
});

test.describe("Inventory locations list (admin)", () => {
    test.use({ account: "admin" });

    test("lists the organization's stores with the default one first", async ({ page }) => {
        await openLocations(page);

        await expect(cell(rows(page).first(), "location")).toContainText(fixture().mainLocation.code);
        await expect(cell(rows(page).first(), "location").locator(".ant-tag")).toHaveText("Default");
        await expect(rowWith(page, fixture().branchLocation.code)).toHaveCount(1);
    });

    test("a row shows the store's code, type, address and contact details", async ({ page }) => {
        await openLocations(page, listUrl(`?search=${fixture().mainLocation.code}`));

        const row = rows(page).first();
        await expect(cell(row, "location")).toContainText(fixture().mainLocation.name);
        await expect(cell(row, "location")).toContainText(fixture().mainLocation.code);
        await expect(cell(row, "type").locator(".ant-tag")).toHaveText("Store");
        await expect(cell(row, "address")).toContainText("Ayala Avenue");
        await expect(cell(row, "contact")).toContainText("Jollibee Store Manager");
        await expect(cell(row, "contact")).toContainText("main@jollibee-corp.com");
        await expect(cell(row, "status").locator(".ant-tag")).toHaveText("Active");
    });

    test("a store without an address or contact details says so", async ({ page }) => {
        const location = await createLocation(page, { address: null });
        await openLocations(page, listUrl(`?search=${location.code}`));

        await expect(cell(rows(page).first(), "address")).toHaveText("No address");
        await expect(cell(rows(page).first(), "contact")).toHaveText("No contact info");
    });

    test("the products column counts the store's own stock", async ({ page }) => {
        await openLocations(page, listUrl(`?search=${fixture().store.code}`));

        await expect(cell(rows(page).first(), "products")).toContainText(String(fixture().summary.total));
    });

    test("a new store starts with no products", async ({ page }) => {
        const location = await createLocation(page);
        await openLocations(page, listUrl(`?search=${location.code}`));

        await expect(cell(rows(page).first(), "products")).toContainText("0");
    });

    for (const [what, text] of [
        ["code", () => fixture().store.code],
        ["name", () => fixture().store.name],
        ["contact person", () => "Jollibee Warehouse Manager"],
    ]) {
        test(`search finds a store by ${what}`, async ({ page }) => {
            await openLocations(page);

            await search(page, text());

            await expect(rows(page)).toHaveCount(1);
        });
    }

    test("the type filter shows only that type", async ({ page }) => {
        await openLocations(page);

        await page.locator("button:has(.anticon-filter)").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("type") === "warehouse");
        await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Type" }), 0, "Warehouse");
        await loaded;

        await expect(rows(page)).toHaveCount(1);
        await expect(cell(rows(page).first(), "type").locator(".ant-tag")).toHaveText("Warehouse");
    });

    test("the status filter shows the inactive stores, which the list otherwise mixes in", async ({ page }) => {
        await openLocations(page);
        await expect(rowWith(page, fixture().store.code), "an inactive store is listed too").toHaveCount(1);

        await page.locator("button:has(.anticon-filter)").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("status") === "inactive");
        await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Status" }), 0, "Inactive");
        await loaded;

        await expect(rowWith(page, fixture().store.code)).toHaveCount(1);
        await expect(rowWith(page, fixture().mainLocation.code), "the active default store").toHaveCount(0);
        for (const status of await cell(rows(page), "status").allInnerTexts()) {
            expect(status.trim()).toBe("Inactive");
        }
    });

    test("the store being worked in is named on the page", async ({ page }) => {
        await openLocations(page);

        await expect(page.locator(".ant-alert")).toContainText(fixture().mainLocation.name);
    });

    test("only this organization's stores are listed", async ({ page }) => {
        await openLocations(page);

        const { locations } = await pageProps(page);
        expect([...new Set(locations.data.map((l) => l.domain))]).toEqual(["jollibee-corp"]);
    });
});

test.describe("Location actions (admin)", () => {
    test.use({ account: "admin" });
    // These create stores, and a store's code has to be unique. One at a time, in order.
    test.describe.configure({ mode: "default" });

    test("the default store can't be deleted and isn't offered as the default again", async ({ page }) => {
        await openLocations(page, listUrl(`?search=${fixture().mainLocation.code}`));

        const menu = await openRowMenu(page, rows(page).first());

        await expect(menuItem(menu, "Delete")).toHaveClass(/ant-dropdown-menu-item-disabled/);
        await expect(menuItem(menu, "Set as Default")).toHaveCount(0);
    });

    test("a store that still holds stock can't be deleted", async ({ page }) => {
        await openLocations(page, listUrl(`?search=${fixture().store.code}`));

        const menu = await openRowMenu(page, rows(page).first());

        await expect(menuItem(menu, "Delete")).toHaveClass(/ant-dropdown-menu-item-disabled/);
    });

    test("deactivating a store keeps it on the list, so it can be switched back on", async ({ page }) => {
        const location = await createLocation(page);
        await openLocations(page, listUrl(`?search=${location.code}`));

        await menuItem(await openRowMenu(page, rows(page).first()), "Deactivate").click();

        await expect(notice(page, "Location status updated")).toBeVisible();
        await expect(cell(rows(page).first(), "status").locator(".ant-tag")).toHaveText("Inactive");

        // And back on again, which was impossible while the list only showed active stores.
        await menuItem(await openRowMenu(page, rows(page).first()), "Activate").click();

        await expect(cell(rows(page).first(), "status").locator(".ant-tag")).toHaveText("Active");
    });

    test("deleting an empty store removes it from the list", async ({ page }) => {
        const location = await createLocation(page);
        await openLocations(page, listUrl(`?search=${location.code}`));

        await menuItem(await openRowMenu(page, rows(page).first()), "Delete").click();
        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText(`Are you sure you want to delete "${location.name}"?`);
        await confirm.getByRole("button", { name: "Delete" }).click();

        await expect(rows(page)).toHaveCount(0);
        await expect(notice(page, "Location deleted")).toBeVisible();
    });

    test("cancelling the delete confirmation keeps the store", async ({ page }) => {
        const location = await createLocation(page);
        await openLocations(page, listUrl(`?search=${location.code}`));

        await menuItem(await openRowMenu(page, rows(page).first()), "Delete").click();
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Cancel" }).click();

        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(location.code);
    });

    test("Add Location opens the create form", async ({ page }) => {
        await openLocations(page);

        await page.getByRole("button", { name: "Add Location" }).click();

        await expect(page).toHaveURL(new RegExp(`${locationsPath}/create$`));
        await expect(page).toHaveTitle(/Create Location/);
    });

    test("Edit Location opens the store's form", async ({ page }) => {
        const location = await createLocation(page);
        await openLocations(page, listUrl(`?search=${location.code}`));

        await rows(page).first().getByRole("button", { name: "Edit Location" }).click();

        await expect(page).toHaveURL(new RegExp(`${locationsPath}/${location.id}/edit$`));
        await expect(page.getByText(`Edit ${location.name}`)).toBeVisible();
        const nameField = page.locator(".ant-form-item").filter({ hasText: "Location Name" }).locator("input");
        await expect(nameField).toHaveValue(location.name);
    });

    test("View Details opens the store's page with its stock summary", async ({ page }) => {
        await openLocations(page, listUrl(`?search=${fixture().store.code}`));

        await rows(page).first().getByRole("button", { name: "View Details" }).click();

        await expect(page).toHaveURL(new RegExp(`${locationsPath}/${fixture().store.id}$`));
        await expect(page.getByRole("heading", { name: fixture().store.name }).first()).toBeVisible();
        await expect(page.getByText("Total Products").first()).toBeVisible();
    });
});

test.describe("Inventory locations as a manager", () => {
    test.use({ account: "manager" });

    test("only the store they are assigned to", async ({ page }) => {
        await openLocations(page);

        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(fixture().mainLocation.code);
    });

    test("no adding, editing or store actions they may not perform", async ({ page }) => {
        await openLocations(page);

        await expect(page.getByRole("button", { name: "Add Location" })).toHaveCount(0);
        await expect(rows(page).first().getByRole("button", { name: "Edit Location" })).toHaveCount(0);
        await expect(rows(page).first().getByRole("button", { name: "More Actions" })).toHaveCount(0);
        await expect(rows(page).first().getByRole("button", { name: "View Details" })).toBeVisible();
    });
});

test.describe("Inventory locations access (API)", () => {
    test("the page size is capped", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { locations } = await responseProps(await api.get(listUrl("?per_page=100000")));

        expect(locations.meta.per_page).toBeLessThanOrEqual(100);
    });

    for (const query of ["?per_page=0", "?per_page=abc", "?type=bogus", "?status=bogus", "?page=-1"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(listUrl(query))).status()).toBeLessThan(500);
        });
    }

    test("the default store can't be deleted", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await apiJson(api, "DELETE", `${locationsPath}/${fixture().mainLocation.id}`);

        expect(res.status).toBe(422);
        const { locations } = await responseProps(await api.get(listUrl(`?search=${fixture().mainLocation.code}`)));
        expect(locations.data, "the default store is still there").toHaveLength(1);
    });

    test("a store that still holds stock can't be deleted", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await apiJson(api, "DELETE", `${locationsPath}/${fixture().store.id}`);

        expect(res.status).toBe(422);
        const { locations } = await responseProps(await api.get(listUrl(`?search=${fixture().store.code}`)));
        expect(locations.data, "the store is still there").toHaveLength(1);
    });

    test("a manager can't add, change or delete a store", async ({ serverAs }) => {
        const api = await serverAs("manager");

        const create = await apiJson(api, "POST", locationsPath, { name: "E2E Manager Store", code: "E2E-MGR", type: "store" });
        const remove = await apiJson(api, "DELETE", `${locationsPath}/${fixture().branchLocation.id}`);

        expect(create.status, "create").toBe(403);
        expect(remove.status, "delete").toBe(403);
    });

    test("a cashier can't add a store", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        const res = await apiJson(api, "POST", locationsPath, { name: "E2E Cashier Store", code: "E2E-CSH", type: "store" });

        expect(res.status).toBe(403);
    });

    test("another organization's store can't be opened or deleted", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const otherOrg = fixture().otherOrgLocationId;

        expect((await apiJson(api, "GET", `${locationsPath}/${otherOrg}`)).status, "open").toBe(403);
        expect((await apiJson(api, "DELETE", `${locationsPath}/${otherOrg}`)).status, "delete").toBe(403);
    });

    test("another organization's manager can't open Jollibee's stores", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", locationsPath)).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(locationsPath);
        await expect(page).toHaveURL(/\/login$/);
    });
});
