import { test, expect, apiJson } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";
import { newProductValues, productFixture, productUrl, productsFixtures, productsUrl } from "../support/products.js";

const expect422 = (res, field) => {
    expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
    expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
};

/** A valid create payload with unique name, SKU and barcode. */
const validProduct = (label, extra = {}) => {
    const v = newProductValues(label);
    return { name: v.name, SKU: v.sku, barcode: v.barcode, price: 10, sold_type: "Piece", ...extra };
};

/** Finds a product this store lists by exact name (index props). */
async function findInStore(api, name, query = "") {
    const res = await api.get(`${productsUrl()}?search=${encodeURIComponent(name)}${query}`);
    const props = await responseProps(res);
    return props.items.data.find((p) => p.name === name) ?? null;
}

test.describe("Products API as a store manager", () => {
    let api;
    let store;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        api = await serverAs("wallet-manager");
        store = productFixture(testInfo);
    });

    test.describe("create validation", () => {
        const cases = [
            { name: "a missing name", patch: { name: undefined }, field: "name" },
            { name: "a name over 255 characters", patch: { name: "x".repeat(256) }, field: "name" },
            { name: "a missing price", patch: { price: undefined }, field: "price" },
            { name: "a negative price", patch: { price: -1 }, field: "price" },
            { name: "a non-numeric price", patch: { price: "cheap" }, field: "price" },
            { name: "a negative cost", patch: { cost: -5 }, field: "cost" },
            { name: "a missing sold type", patch: { sold_type: undefined }, field: "sold_type" },
            { name: "an unknown sold type", patch: { sold_type: "Barrel" }, field: "sold_type" },
            { name: "a nonexistent category", patch: { category_id: 999999 }, field: "category_id" },
            { name: "an SKU over 255 characters", patch: { SKU: "S".repeat(256) }, field: "SKU" },
            { name: "a barcode over 255 characters", patch: { barcode: "1".repeat(256) }, field: "barcode" },
            { name: "an unknown display style", patch: { representation_type: "hologram" }, field: "representation_type" },
            { name: "a non-boolean track inventory flag", patch: { track_inventory: "sometimes" }, field: "track_inventory" },
            { name: "a negative reorder level", patch: { reorder_level: -1 }, field: "reorder_level" },
            { name: "a negative max stock level", patch: { max_stock_level: -1 }, field: "max_stock_level" },
            { name: "a negative unit weight", patch: { unit_weight: -0.5 }, field: "unit_weight" },
            { name: "image style without an image", patch: { representation_type: "image" }, field: "representation_image" },
        ];

        for (const { name, patch, field } of cases) {
            test(`rejects ${name}`, async () => {
                const body = validProduct("Val", patch);
                for (const key of Object.keys(body)) if (body[key] === undefined) delete body[key];

                expect422(await apiJson(api, "POST", productsUrl(), body), field);
            });
        }

        test("rejects an SKU already used in the organization", async () => {
            expect422(await apiJson(api, "POST", productsUrl(), validProduct("DupSku", { SKU: store.a.sku })), "SKU");
        });

        test("rejects a barcode already used in the organization", async () => {
            expect422(await apiJson(api, "POST", productsUrl(), validProduct("DupBar", { barcode: store.a.barcode })), "barcode");
        });

        test("rejects a name already used at the store", async () => {
            expect422(await apiJson(api, "POST", productsUrl(), validProduct("DupName", { name: store.a.name })), "name");
        });
    });

    test.describe("create", () => {
        test("adds the product to the manager's store with defaults", async () => {
            const body = validProduct("Api");

            const res = await apiJson(api, "POST", productsUrl(), body);

            expect([200, 302]).toContain(res.status);
            const created = await findInStore(api, body.name);
            expect(created, "listed at the manager's store").toBeTruthy();
            expect(created).toMatchObject({ SKU: body.SKU, barcode: body.barcode, representation_type: "color", representation: "94a3b8", cost: null });
        });

        test("SKU and barcode are trimmed", async () => {
            const body = validProduct("Trim");

            await apiJson(api, "POST", productsUrl(), { ...body, SKU: `  ${body.SKU}  `, barcode: `  ${body.barcode}  ` });

            const created = await findInStore(api, body.name);
            expect(created.SKU).toBe(body.SKU);
            expect(created.barcode).toBe(body.barcode);
        });

        test("the same name is allowed at a different store", async ({ serverAs }) => {
            const admin = await serverAs("admin");
            const { mainLocationId } = productsFixtures();

            const res = await apiJson(admin, "POST", `${productsUrl()}?location_id=${mainLocationId}`, validProduct("OtherStore", { name: store.a.name, location_id: mainLocationId }));

            expect([200, 302], `got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 160)}`).toContain(res.status);
        });

        test("several products can be created without a barcode", async () => {
            for (const label of ["ApiNoBar1", "ApiNoBar2"]) {
                const body = validProduct(label);
                delete body.barcode;

                const res = await apiJson(api, "POST", productsUrl(), body);

                expect([200, 302], `${label}: got ${res.status} (barcode is optional)`).toContain(res.status);
            }
        });
    });

    test.describe("update", () => {
        test("updates fields and keeps its own SKU and barcode", async () => {
            const res = await apiJson(api, "PUT", productUrl(store.c.id), {
                name: store.c.name,
                SKU: store.c.sku,
                barcode: `E2E-W-BAR-C-${store.c.id}`,
                price: 47.25,
                sold_type: "Pack",
            });

            expect([200, 302], `got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 160)}`).toContain(res.status);
            expect((await findInStore(api, store.c.name)).price).toBe(47.25);
        });

        test("rejects taking another product's SKU", async () => {
            expect422(await apiJson(api, "PUT", productUrl(store.c.id), { name: store.c.name, SKU: store.a.sku, price: 45.5, sold_type: "Pack" }), "SKU");
        });

        test("rejects renaming to another product's name at the store", async () => {
            expect422(await apiJson(api, "PUT", productUrl(store.c.id), { name: store.a.name, price: 45.5, sold_type: "Pack" }), "name");
        });

        test("can't update another organization's product", async () => {
            const res = await apiJson(api, "PUT", productUrl(productsFixtures().otherOrg.productId), { name: "Hijack", price: 1, sold_type: "Piece" });

            expect(res.status).toBe(403);
        });

        test("can't open another organization's product for editing", async () => {
            expect((await apiJson(api, "GET", productUrl(productsFixtures().otherOrg.productId, "/edit"))).status).toBe(403);
        });
    });

    test.describe("permissions", () => {
        test("a manager can't delete products", async () => {
            expect((await apiJson(api, "DELETE", productUrl(store.c.id))).status).toBe(403);
            expect(await findInStore(api, store.c.name)).toBeTruthy();
        });
    });

    test.describe("organization boundaries", () => {
        test("can't use another organization's category", async () => {
            const res = await apiJson(api, "POST", productsUrl(), validProduct("ForeignCat", { category_id: productsFixtures().otherOrg.categoryId }));

            expect([403, 422], `assigning a McDonald's category got ${res.status}`).toContain(res.status);
        });

        test("an SKU used by another organization is still available", async () => {
            const res = await apiJson(api, "POST", productsUrl(), validProduct("ForeignSku", { SKU: productsFixtures().otherOrg.sku }));

            expect([200, 302], `SKUs should be unique per organization, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 160)}`).toContain(res.status);
        });

        test("a barcode used by another organization is still available", async ({ serverAs }) => {
            const body = validProduct("ForeignBarcode", { barcode: productsFixtures().otherOrg.barcode });

            const res = await apiJson(api, "POST", productsUrl(), body);

            expect([200, 302], `got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 160)}`).toContain(res.status);
            // Remove it again: the barcode must stay free for the next run or repeat.
            const created = await findInStore(api, body.name);
            const admin = await serverAs("admin");
            await apiJson(admin, "DELETE", `${productUrl(created.id)}?location_id=${store.storeId}`);
        });
    });

    test.describe("list filters", () => {
        test("the price filter matches the exact price", async () => {
            const props = await responseProps(await api.get(`${productsUrl()}?price=${store.b.price}`));

            expect(props.items.data.map((p) => p.name)).toEqual([store.b.name]);
        });

        test("the cost filter matches the exact cost", async () => {
            const props = await responseProps(await api.get(`${productsUrl()}?cost=200`));

            expect(props.items.data.map((p) => p.name)).toEqual([store.b.name]);
        });

        test("the sold type filter matches the sold type", async () => {
            const props = await responseProps(await api.get(`${productsUrl()}?sold_type=Pack`));

            expect(props.items.data.map((p) => p.name)).toContain(store.c.name);
            expect(props.items.data.every((p) => p.sold_type === "Pack")).toBe(true);
        });
    });
});

test.describe("Products API as an admin", () => {
    let admin;
    let store;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        admin = await serverAs("admin");
        store = productFixture(testInfo);
    });

    test("can't attach a new product to another organization's store", async ({ serverAs }) => {
        const { otherOrg } = productsFixtures();
        const body = validProduct("ForeignStore", { location_id: otherOrg.locationId });

        const res = await apiJson(admin, "POST", `${productsUrl()}?location_id=${otherOrg.locationId}`, body);

        if (res.status >= 400) {
            expect([403, 404, 422]).toContain(res.status);
            return;
        }
        // Accepted: make sure it didn't land in McDonald's store catalog.
        const mcManager = await serverAs("mcManager");
        const mcList = await mcManager.get(`/domains/mcdonalds-corp/products?search=${encodeURIComponent(body.name)}`);
        const listed = (await responseProps(mcList)).items.data.some((p) => p.name === body.name);
        expect(listed, "a Jollibee product must never be offered at a McDonald's store").toBe(false);
    });

    test("deletes a product", async () => {
        const body = validProduct("AdminDelete", { location_id: store.storeId });
        await apiJson(admin, "POST", `${productsUrl()}?location_id=${store.storeId}`, body);
        const created = await findInStore(admin, body.name, `&location_id=${store.storeId}`);

        const res = await apiJson(admin, "DELETE", `${productUrl(created.id)}?location_id=${store.storeId}`);

        expect([200, 302]).toContain(res.status);
        expect(await findInStore(admin, body.name, `&location_id=${store.storeId}`)).toBeNull();
    });

    test("can't delete another organization's product", async () => {
        expect((await apiJson(admin, "DELETE", productUrl(productsFixtures().otherOrg.productId))).status).toBe(403);
    });

    test.describe("assignable and attach", () => {
        test("assignable requires an active store of this organization", async () => {
            const { otherOrg } = productsFixtures();

            expect422(await apiJson(admin, "GET", productsUrl("/assignable")), "location_id");
            expect((await apiJson(admin, "GET", `${productsUrl("/assignable")}?location_id=${otherOrg.locationId}`)).status, "other organization").toBe(404);
            expect((await apiJson(admin, "GET", `${productsUrl("/assignable")}?location_id=${store.storeId}`)).status, "inactive store").toBe(404);
        });

        test("attaching twice reports already attached", async () => {
            const { mainLocationId } = productsFixtures();

            const res = await apiJson(admin, "POST", productUrl(store.attach.id, "/attach-location"), { location_id: mainLocationId });

            expect(res.status).toBe(200);
            expect(res.body).toMatchObject({ success: true, already_attached: true });
        });

        test("can't attach to another organization's store", async () => {
            const res = await apiJson(admin, "POST", productUrl(store.attach.id, "/attach-location"), { location_id: productsFixtures().otherOrg.locationId });

            expect(res.status).toBe(404);
        });

        test("can't attach another organization's product", async () => {
            const res = await apiJson(admin, "POST", productUrl(productsFixtures().otherOrg.productId, "/attach-location"), { location_id: productsFixtures().branchLocationId });

            expect(res.status).toBe(403);
        });
    });
});

test.describe("Products API as a cashier", () => {
    test("can list products but not create, update or delete", async ({ serverAs }) => {
        const api = await serverAs("worker-cashier");
        const { a } = productsFixtures().workers[1];

        expect((await api.get(productsUrl())).status()).toBe(200);
        expect((await apiJson(api, "POST", productsUrl(), validProduct("Cashier"))).status, "create").toBe(403);
        expect((await apiJson(api, "PUT", productUrl(a.id), { name: "Cashier edit", price: 1, sold_type: "Piece" })).status, "update").toBe(403);
        expect((await apiJson(api, "DELETE", productUrl(a.id))).status, "delete").toBe(403);
    });
});

test.describe("Products API for another organization", () => {
    test("a McDonald's manager can't read or change Jollibee products", async ({ serverAs }) => {
        const api = await serverAs("mcManager");
        const { a } = productsFixtures().workers[1];

        expect((await apiJson(api, "GET", productsUrl())).status).toBe(403);
        expect((await apiJson(api, "PUT", productUrl(a.id), { name: "Intruder", price: 1, sold_type: "Piece" })).status).toBe(403);
    });
});
