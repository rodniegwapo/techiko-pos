import { test, expect, apiJson } from "../support/fixtures.js";
import { notice } from "../support/antd.js";
import { fixtureIds } from "../support/sales.js";

/**
 * Transferring stock between stores (Inventory > Products > Transfer Stock), several products at once.
 *
 * Each run creates its own products at the main store and receives stock for them, so the stores'
 * seeded stock that other suites assert on is left alone.
 */

const DOMAIN = "jollibee-corp";
const org = (path = "") => `/domains/${DOMAIN}${path}`;
const locations = () => fixtureIds().inventoryDashboard;

/** Creates a product at the main store with `qty` received there; returns { id, name }. */
async function stockedProduct(api, label, qty) {
    const { mainLocation, branchLocation } = locations();
    const code = `${label}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`.toUpperCase();
    const name = `E2E New Xfer ${code}`;

    const created = await apiJson(api, "POST", `${org("/products")}?location_id=${mainLocation.id}`, {
        name,
        SKU: `E2E-NEW-${code}`,
        barcode: `E2E-NEW-${code}`,
        price: 10,
        sold_type: "Piece",
        location_id: mainLocation.id,
    });
    expect(created.status, `create ${name}`).toBeLessThan(400);

    // Not at the branch yet, so the branch's "Add existing to store" list offers it: that gives its id.
    const offered = await apiJson(api, "GET", `${org("/products/assignable")}?location_id=${branchLocation.id}&search=${encodeURIComponent(name)}`);
    const product = offered.body.data.find((p) => p.name === name);
    expect(product, `${name} exists`).toBeTruthy();

    const received = await apiJson(api, "POST", "/api/inventory/receive", {
        location_id: mainLocation.id,
        items: [{ product_id: product.id, quantity: qty, unit_cost: 5 }],
    });
    expect(received.status, `receive ${name}`).toBeLessThan(400);

    return { id: product.id, name };
}

async function stockAt(api, productId, locationId) {
    const res = await apiJson(api, "GET", `/api/inventory/products?product_id=${productId}&location_id=${locationId}&per_page=1`);
    return Number(res.body.data?.[0]?.quantity_available ?? 0);
}

test.describe("Transfer stock (admin)", () => {
    test.use({ account: "admin" });

    test("moves several products to another store in one transfer", async ({ page, serverAs }) => {
        const api = await serverAs("admin");
        const { mainLocation, branchLocation } = locations();
        const cups = await stockedProduct(api, "CUPS", 10);
        const lids = await stockedProduct(api, "LIDS", 6);

        await page.goto(org(`/inventory/products?location_id=${mainLocation.id}`));
        // The toolbar button (each product row has its own "Transfer Stock" too).
        await page.getByRole("button", { name: "Transfer Stock", exact: true }).first().click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Transfer Inventory" });
        await expect(dialog).toBeVisible();

        // Destination store.
        await dialog.locator(".ant-select").nth(1).click();
        await page
            .locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option")
            .filter({ hasText: branchLocation.name })
            .click();

        for (const product of [cups, lids]) {
            const results = page.waitForResponse((r) => r.url().includes("/api/inventory/search/products"));
            await dialog.getByPlaceholder(/Search products to add/).fill(product.name);
            await results;
            await dialog.getByText(product.name, { exact: true }).click();
            await expect(dialog.getByRole("spinbutton", { name: `Quantity of ${product.name}` })).toBeVisible();
        }

        await dialog.getByRole("spinbutton", { name: `Quantity of ${cups.name}` }).fill("4");
        await dialog.getByRole("spinbutton", { name: `Quantity of ${lids.name}` }).fill("6");
        await expect(dialog).toContainText("2 products • 10 units");

        const posted = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/inventory/transfer"));
        await dialog.getByRole("button", { name: "Transfer Inventory" }).click();
        expect((await posted).status()).toBe(200);
        await expect(notice(page, "Transfer Successful")).toContainText("2 products transferred");

        expect(await stockAt(api, cups.id, mainLocation.id)).toBe(6);
        expect(await stockAt(api, cups.id, branchLocation.id)).toBe(4);
        expect(await stockAt(api, lids.id, mainLocation.id)).toBe(0);
        expect(await stockAt(api, lids.id, branchLocation.id)).toBe(6);
    });

    test("a short row moves nothing and is named in the answer", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { mainLocation, branchLocation } = locations();
        const cups = await stockedProduct(api, "SCUP", 5);
        const lids = await stockedProduct(api, "SLID", 2);

        const res = await apiJson(api, "POST", "/inventory/transfer", {
            from_location_id: mainLocation.id,
            to_location_id: branchLocation.id,
            items: [
                { product_id: cups.id, quantity: 3 },
                { product_id: lids.id, quantity: 5 },
            ],
        });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors)).toEqual(["items.1.quantity"]);
        expect(await stockAt(api, cups.id, mainLocation.id)).toBe(5);
        expect(await stockAt(api, lids.id, mainLocation.id)).toBe(2);
    });
});
