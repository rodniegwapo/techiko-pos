import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption, notice } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The stock adjustments page (Inventory > Adjustments).
 *
 * Fixtures come from database/seeders/E2EStockAdjustmentSeeder.php: the E2E-ADJ store holds 24
 * adjustments with exact numbers, reasons, item counts and values for the read-only assertions,
 * while the workflow tests create their own at the empty E2E-ADJF store, because approving one
 * moves that store's stock.
 *
 * Not covered: the Export button, which is still a stub that only logs to the console.
 */

const fixture = () => fixtureIds().stockAdjustments;
const adj = () => fixture().adjustments;
const adjustmentsPath = "/domains/jollibee-corp/inventory/adjustments";
const storeUrl = (locationId = fixture().listStore.id, query = "") =>
    `${adjustmentsPath}?location_id=${locationId}${query}`;
const flowUrl = (query = "") => storeUrl(fixture().flowStore.id, query);

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const cell = (row, name) => row.locator("td").nth(["number", "reason", "status", "items", "value"].indexOf(name));

async function openAdjustments(page, url = storeUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const loaded = page.waitForResponse((r) => {
        const url = new URL(r.url());
        return url.pathname === adjustmentsPath && url.searchParams.get("search") === text;
    });
    await page.getByPlaceholder("Search adjustments, reasons, or notes...").fill(text);
    await loaded;
}

async function openDetails(page, rowText) {
    await rowWith(page, rowText).first().getByRole("button", { name: "View Details" }).click();
    const dialog = page.getByRole("dialog").filter({ hasText: "Stock Adjustment Details" });
    await expect(dialog).toBeVisible();
    return dialog;
}

/** Totals tile in the details dialog, e.g. tile(dialog, "Value Impact"). */
const tile = (dialog, label) => dialog.locator("div.rounded-lg.text-center").filter({ hasText: label });

/** Stock on hand of a flow-store product, as the adjustment form reads it. */
async function flowStock(api, sku) {
    const { flowStore } = fixture();
    const res = await apiJson(
        api,
        "GET",
        `/domains/jollibee-corp/inventory/adjustment-products?location_id=${flowStore.id}&search=${encodeURIComponent(sku)}`,
    );

    expect(res.status, `stock of ${sku}`).toBe(200);
    return res.body.data.find((p) => p.SKU === sku).current_stock;
}

/** A draft adjustment at the flow store, created the way the Create page does it. */
async function createDraft(page, { actualQuantity, description }) {
    const { flowStore, products } = fixture();
    const res = await apiJson(page.request, "POST", adjustmentsPath, {
        location_id: flowStore.id,
        type: "decrease",
        reason: "damaged_goods",
        description,
        items: [{ product_id: products.gadget.id, actual_quantity: actualQuantity, unit_cost: products.gadget.cost }],
    });

    expect(res.status, "create a draft adjustment").toBe(200);
    return res.body.adjustment;
}

test.describe("Stock adjustments list (admin)", () => {
    test.use({ account: "admin" });

    test("lists the store's adjustments newest first, 20 per page", async ({ page }) => {
        await openAdjustments(page);

        await expect(rows(page)).toHaveCount(fixture().perPage);
        await expect(cell(rows(page).first(), "number")).toContainText(adj().draft.number);
        await expect(cell(rows(page).nth(1), "number")).toContainText(adj().pending.number);
        await expect(page.locator(".ant-pagination")).toContainText(`of ${adj().total} items`);
    });

    test("the number column also shows the description", async ({ page }) => {
        await openAdjustments(page);

        await expect(cell(rows(page).first(), "number")).toContainText(adj().draft.description);
    });

    test("reasons show readable labels, not the raw values", async ({ page }) => {
        await openAdjustments(page);

        for (const key of ["draft", "pending", "approved", "rejected"]) {
            await expect(cell(rowWith(page, adj()[key].number), "reason")).toHaveText(adj()[key].reason);
        }
        await expect(rows(page).filter({ hasText: "physical_count" }), "raw reason").toHaveCount(0);
        await expect(rows(page).filter({ hasText: "damaged_goods" }), "raw reason").toHaveCount(0);
    });

    test("statuses show readable labels", async ({ page }) => {
        await openAdjustments(page);

        for (const [key, label] of [
            ["draft", "Draft"],
            ["pending", "Pending Approval"],
            ["approved", "Approved"],
            ["rejected", "Rejected"],
        ]) {
            await expect(cell(rowWith(page, adj()[key].number), "status").locator(".ant-tag")).toHaveText(label);
        }
    });

    test("each row shows how many products it adjusts and the signed value", async ({ page }) => {
        await openAdjustments(page);

        await expect(cell(rowWith(page, adj().draft.number), "items")).toHaveText("2");
        await expect(cell(rowWith(page, adj().draft.number), "value")).toHaveText("+₱40.00");
        await expect(cell(rowWith(page, adj().pending.number), "items")).toHaveText("1");
        await expect(cell(rowWith(page, adj().pending.number), "value")).toHaveText("-₱25.00");
        await expect(cell(rowWith(page, adj().rejected.number), "value")).toHaveText("-₱62.50");
    });

    test("the store being viewed is named on the page", async ({ page }) => {
        await openAdjustments(page);

        await expect(page.locator(".ant-alert")).toContainText(fixture().listStore.name);
    });

    for (const [what, text, expected] of [
        ["number", () => adj().pending.number, () => adj().pending.number],
        ["reason", () => "damaged", () => adj().pending.number],
        ["description", () => adj().rejected.description, () => adj().rejected.number],
    ]) {
        test(`search finds an adjustment by ${what}`, async ({ page }) => {
            await openAdjustments(page);

            await search(page, text());

            await expect(rows(page)).toHaveCount(1);
            await expect(rows(page).first()).toContainText(expected());
        });
    }

    test("searching from page 2 shows the match instead of an empty list", async ({ page }) => {
        await openAdjustments(page);
        await page.locator(".ant-pagination-item-2").click();
        await expect(rows(page)).toHaveCount(adj().total - fixture().perPage);

        await search(page, adj().pending.number);

        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(adj().pending.number);
    });

    test("the status filter keeps the store", async ({ page }) => {
        await openAdjustments(page);

        await page.locator("button:has(.anticon-filter)").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("status") === "pending_approval");
        await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Status" }), 0, "Pending Approval");
        const res = await loaded;

        expect(new URL(res.url()).searchParams.get("location_id"), "store kept").toBe(String(fixture().listStore.id));
        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(adj().pending.number);
    });

    test("page 2 shows the rest of the store's adjustments", async ({ page }) => {
        await openAdjustments(page);

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        const res = await loaded;

        expect(new URL(res.url()).searchParams.get("location_id"), "store kept").toBe(String(fixture().listStore.id));
        await expect(rows(page)).toHaveCount(adj().total - fixture().perPage);
        await expect(rows(page).first()).toContainText(adj().page2First);
    });

    test("a store in another organization shows no adjustments", async ({ page }) => {
        await openAdjustments(page, storeUrl(fixture().otherOrgLocationId));

        await expect(rows(page)).toHaveCount(0);
        await expect(page.locator(".ant-table-placeholder")).toContainText("No stock adjustments found");
    });

    test("adjustment data doesn't include the whole user account", async ({ page }) => {
        await openAdjustments(page);

        const { adjustments } = await pageProps(page);
        const createdBy = adjustments.data.find((a) => a.created_by)?.created_by;

        expect(createdBy, "an adjustment with a creator").toBeTruthy();
        expect(Object.keys(createdBy).sort(), "creator fields sent to the browser").toEqual(["id", "name"]);
    });
});

test.describe("Stock adjustment details (admin)", () => {
    test.use({ account: "admin" });

    test("shows the products, quantities, cost impact, creator and reason", async ({ page }) => {
        const { products } = fixture();
        await openAdjustments(page);

        const dialog = await openDetails(page, adj().draft.number);

        await expect(dialog).toContainText(adj().draft.number);
        await expect(dialog.locator(".ant-tag")).toHaveText(/Draft/);
        await expect(tile(dialog, "Products Adjusted")).toContainText("2");
        await expect(tile(dialog, "Total Quantity")).toContainText("6");
        await expect(dialog).toContainText(fixture().listStore.name);
        await expect(dialog).toContainText(fixture().createdBy.name);
        await expect(dialog).toContainText(adj().draft.reason);

        const widget = dialog.locator("div.border-b").filter({ hasText: products.widget.sku });
        await expect(widget).toContainText("40");
        await expect(widget).toContainText("44");
        await expect(widget).toContainText("+4");
        await expect(widget).toContainText("₱50.00");
    });

    test("the value impact adds the lines up instead of showing zero", async ({ page }) => {
        await openAdjustments(page);

        const dialog = await openDetails(page, adj().draft.number);

        // +4 widgets at ₱12.50 and -2 gadgets at ₱5.00.
        await expect(tile(dialog, "Value Impact")).toContainText("₱40.00");
        await expect(dialog.locator("div.bg-gray-50").filter({ hasText: "Total Impact" })).toContainText("₱40.00");
    });

    test("an approved adjustment names who approved it and when", async ({ page }) => {
        await openAdjustments(page);

        const dialog = await openDetails(page, adj().approved.number);

        await expect(dialog).toContainText("Approved By:");
        await expect(dialog).toContainText(fixture().createdBy.name);
        await expect(dialog).toContainText("Approved:");
        await expect(tile(dialog, "Value Impact")).toContainText("-₱5.00");
    });

    test("clicking the adjustment number opens the details", async ({ page }) => {
        await openAdjustments(page);

        await cell(rows(page).first(), "number").locator("p.font-mono").click();

        await expect(page.getByRole("dialog").filter({ hasText: "Stock Adjustment Details" })).toContainText(
            adj().draft.number,
        );
    });

    test("opening an adjustment's own URL lands on the list with it in view", async ({ page }) => {
        await page.goto(`${adjustmentsPath}/${adj().draft.id}`);

        await expect(page.locator(".ant-table")).toBeVisible();
        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(adj().draft.number);
    });
});

test.describe("Stock adjustment actions (admin)", () => {
    test.use({ account: "admin" });
    // These create adjustments, and adjustment numbers are handed out by "highest so far + 1",
    // which two simultaneous creates would collide on. One at a time, in order.
    test.describe.configure({ mode: "default" });

    test("a draft offers submit, edit and delete; an approved one only details", async ({ page }) => {
        await openAdjustments(page);

        const draft = rowWith(page, adj().draft.number);
        for (const name of ["View Details", "Submit for Approval", "Edit", "Delete"]) {
            await expect(draft.getByRole("button", { name }), name).toBeVisible();
        }

        const approved = rowWith(page, adj().approved.number);
        await expect(approved.getByRole("button", { name: "View Details" })).toBeVisible();
        await expect(approved.locator("button")).toHaveCount(1);
    });

    test("a pending adjustment offers approve and reject", async ({ page }) => {
        await openAdjustments(page);

        const pending = rowWith(page, adj().pending.number);

        await expect(pending.getByRole("button", { name: "Approve" })).toBeVisible();
        await expect(pending.getByRole("button", { name: "Reject" })).toBeVisible();
        await expect(pending.getByRole("button", { name: "Submit for Approval" })).toHaveCount(0);
    });

    test("submitting a draft sends it for approval", async ({ page }) => {
        const draft = await createDraft(page, { actualQuantity: 18, description: "E2E submit flow" });
        await openAdjustments(page, flowUrl(`&search=${draft.adjustment_number}`));

        await rows(page).first().getByRole("button", { name: "Submit for Approval" }).click();

        // The notification comes first: antd dismisses it a few seconds later.
        await expect(notice(page, "Adjustment submitted for approval")).toBeVisible();
        await expect(cell(rows(page).first(), "status")).toContainText("Pending Approval");
    });

    test("approving an adjustment applies the counted quantity to the store's stock", async ({ page, serverAs }) => {
        const { flowStore, products } = fixture();
        const counted = 17;
        const draft = await createDraft(page, { actualQuantity: counted, description: "E2E approve flow" });
        await apiJson(page.request, "POST", `${adjustmentsPath}/${draft.id}/submit`);
        await openAdjustments(page, flowUrl(`&search=${draft.adjustment_number}`));

        await rows(page).first().getByRole("button", { name: "Approve" }).click();

        await expect(notice(page, "Adjustment approved and processed")).toBeVisible();
        await expect(cell(rows(page).first(), "status")).toContainText("Approved");

        const api = await serverAs("admin");
        expect(await flowStock(api, products.gadget.sku), "stock after approval").toBe(counted);

        // The approval is also on the store's movement history, as the adjustment's own reason.
        const { movements } = await responseProps(
            await api.get(
                `/domains/jollibee-corp/inventory/movements?location_id=${flowStore.id}&search=${products.gadget.sku}`,
            ),
        );
        expect(movements.data[0].movement_type_display).toBe("Damaged Goods");
        expect(movements.data[0].quantity_after).toBe(counted);
    });

    test("rejecting an adjustment leaves the stock alone", async ({ page, serverAs }) => {
        const { products } = fixture();
        const draft = await createDraft(page, { actualQuantity: 4, description: "E2E reject flow" });
        await apiJson(page.request, "POST", `${adjustmentsPath}/${draft.id}/submit`);
        await openAdjustments(page, flowUrl(`&search=${draft.adjustment_number}`));

        const api = await serverAs("admin");
        const before = await flowStock(api, products.gadget.sku);

        await rows(page).first().getByRole("button", { name: "Reject" }).click();

        await expect(cell(rows(page).first(), "status")).toContainText("Rejected");
        await expect(rows(page).first().locator("button")).toHaveCount(1);
        expect(await flowStock(api, products.gadget.sku), "stock after rejection").toBe(before);
    });

    test("deleting a draft removes it from the list", async ({ page }) => {
        const draft = await createDraft(page, { actualQuantity: 19, description: "E2E delete flow" });
        await openAdjustments(page, flowUrl(`&search=${draft.adjustment_number}`));

        await rows(page).first().getByRole("button", { name: "Delete" }).click();
        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText("Do you want to delete this adjustment?");
        await confirm.getByRole("button", { name: "Delete" }).click();

        await expect(rows(page)).toHaveCount(0);
        await expect(notice(page, "deleted successfully")).toBeVisible();
    });

    test("cancelling the delete confirmation keeps the adjustment", async ({ page }) => {
        const draft = await createDraft(page, { actualQuantity: 19, description: "E2E keep flow" });
        await openAdjustments(page, flowUrl(`&search=${draft.adjustment_number}`));

        await rows(page).first().getByRole("button", { name: "Delete" }).click();
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Cancel" }).click();

        await expect(rows(page)).toHaveCount(1);
    });
});

test.describe("Stock adjustments as a manager", () => {
    test.use({ account: "manager" });

    test("only their own store's adjustments, even with another store in the URL", async ({ page }) => {
        await openAdjustments(page);

        const { currentLocation, adjustments } = await pageProps(page);
        expect(currentLocation.code).toBe(fixture().mainLocation.code);
        expect(adjustments.data.every((a) => a.location_id === fixture().mainLocation.id)).toBe(true);
    });

    test("no New Adjustment button, since they may not create one", async ({ page }) => {
        await openAdjustments(page, `${adjustmentsPath}`);

        await expect(page.getByRole("button", { name: "New Adjustment" })).toHaveCount(0);
    });

    test("no edit or delete on a draft, but they can send it for approval", async ({ page }) => {
        await openAdjustments(page, `${adjustmentsPath}`);
        const draft = rows(page).filter({ hasText: "Draft" }).first();
        test.skip((await draft.count()) === 0, "no draft adjustment at the manager's store");

        await expect(draft.getByRole("button", { name: "Submit for Approval" })).toBeVisible();
        await expect(draft.getByRole("button", { name: "Edit" })).toHaveCount(0);
        await expect(draft.getByRole("button", { name: "Delete" })).toHaveCount(0);
    });
});

test.describe("Stock adjustments access and filters (API)", () => {
    test("the page size is capped", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { adjustments } = await responseProps(await api.get(storeUrl(undefined, "&per_page=100000")));

        expect(adjustments.meta.per_page).toBeLessThanOrEqual(100);
    });

    test("date filters limit the adjustments to those days", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { adjustments } = await responseProps(
            await api.get(storeUrl(undefined, "&date_from=2026-04-01&date_to=2026-04-02")),
        );

        expect(adjustments.data.map((a) => a.adjustment_number).sort()).toEqual(
            [adj().approved.number, adj().rejected.number].sort(),
        );
    });

    test("a status filter only returns that status", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const { adjustments } = await responseProps(await api.get(storeUrl(undefined, "&status=draft")));

        expect(adjustments.meta.total).toBe(adj().draftCount);
        expect(adjustments.data.every((a) => a.status === "draft")).toBe(true);
    });

    for (const query of ["&per_page=0", "&per_page=abc", "&date_from=not-a-date", "&status=bogus", "&page=-1"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(storeUrl(undefined, query))).status()).toBeLessThan(500);
        });
    }

    test("the global list only shows the user's own organization", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const props = await responseProps(await api.get("/inventory/adjustments"));

        expect(props.isGlobalView, "an organization user isn't shown the global view").toBe(false);
        expect([...new Set(props.locations.map((l) => l.domain))]).toEqual(["jollibee-corp"]);
        expect(props.domains.map((d) => d.name_slug)).toEqual(["jollibee-corp"]);
    });

    test("a cashier can't open the adjustments page", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        expect((await apiJson(api, "GET", storeUrl())).status).toBe(403);
    });

    test("another organization's manager can't open Jollibee adjustments", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", storeUrl())).status).toBe(403);
    });

    test("another organization's adjustment can't be approved", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        const res = await apiJson(api, "POST", `${adjustmentsPath}/${adj().pending.id}/approve`);

        expect(res.status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(storeUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
