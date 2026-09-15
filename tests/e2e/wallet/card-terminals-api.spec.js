import { test, expect, apiJson } from "../support/fixtures.js";
import { fixtureIds } from "../support/sales.js";
import { cardTypeUrl, cardTypesUrl, walletFixture } from "../support/wallet.js";

const uniqueName = (label) => `E2E Terminal ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;
const ymd = (daysAgo) => new Date(Date.now() - daysAgo * 86_400_000).toISOString().slice(0, 10);

const expect422 = (res, field) => {
    expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
    expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
};

test.describe("Card terminals API as a store manager", () => {
    let api;
    let store;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        api = await serverAs("wallet-manager");
        store = walletFixture(testInfo);
    });

    test.describe("create", () => {
        const cases = [
            { name: "a missing name", body: {}, field: "name" },
            { name: "an empty name", body: { name: "" }, field: "name" },
            { name: "a name over 255 characters", body: { name: "x".repeat(256) }, field: "name" },
            { name: "a negative sort order", body: { name: "E2E Terminal Sort", sort_order: -1 }, field: "sort_order" },
            { name: "a sort order over 65535", body: { name: "E2E Terminal Sort", sort_order: 65536 }, field: "sort_order" },
            { name: "a non-numeric sort order", body: { name: "E2E Terminal Sort", sort_order: "first" }, field: "sort_order" },
        ];

        for (const { name, body, field } of cases) {
            test(`rejects ${name}`, async () => {
                expect422(await apiJson(api, "POST", cardTypesUrl(), body), field);
            });
        }

        test("creates an active type in the manager's store", async () => {
            const res = await apiJson(api, "POST", cardTypesUrl(), { name: uniqueName("API"), sort_order: 5 });

            expect(res.status).toBe(201);
            expect(res.body.data).toMatchObject({ location_id: store.locationId, domain: "jollibee-corp", is_active: true, sort_order: 5 });
        });

        test("a manager can't create a type in another store", async () => {
            const res = await apiJson(api, "POST", cardTypesUrl(), {
                name: uniqueName("Elsewhere"),
                location_id: fixtureIds().wallet.mainLocationId,
            });

            expect(res.status).toBe(201);
            expect(res.body.data.location_id, "restricted managers always write to their own store").toBe(store.locationId);
        });

        test("rejects a name already used in the store", async () => {
            const name = uniqueName("Dup");
            await apiJson(api, "POST", cardTypesUrl(), { name });

            const second = await apiJson(api, "POST", cardTypesUrl(), { name });

            expect422(second, "name");
            expect(second.body.errors.name[0]).toBe("A card type with this name already exists at this store.");
        });

        test("the same name is allowed in a different store", async ({ serverAs }) => {
            const name = uniqueName("Shared");
            const mainId = fixtureIds().wallet.mainLocationId;
            expect((await apiJson(api, "POST", cardTypesUrl(), { name })).status, "in this store").toBe(201);
            const admin = await serverAs("admin");

            const res = await apiJson(admin, "POST", cardTypesUrl(), { name, location_id: mainId });

            expect(res.status, "same name at JB-MAIN").toBe(201);
            // JB-MAIN isn't wiped by the seeder, so remove it again.
            await apiJson(admin, "DELETE", `${cardTypeUrl(res.body.data.id)}?location_id=${mainId}`);
        });
    });

    test.describe("update", () => {
        let typeId;

        test.beforeEach(async () => {
            typeId = (await apiJson(api, "POST", cardTypesUrl(), { name: uniqueName("Upd") })).body.data.id;
        });

        const cases = [
            { name: "an empty name", body: { name: "" }, field: "name" },
            { name: "a name over 255 characters", body: { name: "x".repeat(256) }, field: "name" },
            { name: "a non-boolean active flag", body: { is_active: "maybe" }, field: "is_active" },
            { name: "a negative sort order", body: { sort_order: -3 }, field: "sort_order" },
        ];

        for (const { name, body, field } of cases) {
            test(`rejects ${name}`, async () => {
                expect422(await apiJson(api, "PUT", cardTypeUrl(typeId), body), field);
            });
        }

        test("rejects renaming to another card type's name in the store", async () => {
            expect422(await apiJson(api, "PUT", cardTypeUrl(typeId), { name: "E2E Wallet Visa" }), "name");
        });

        test("keeping its own name is allowed", async () => {
            const current = (await apiJson(api, "PUT", cardTypeUrl(typeId), { is_active: true })).body.data.name;

            expect((await apiJson(api, "PUT", cardTypeUrl(typeId), { name: current })).status).toBe(200);
        });

        test("updates only the fields sent", async () => {
            const res = await apiJson(api, "PUT", cardTypeUrl(typeId), { is_active: false });

            expect(res.status).toBe(200);
            expect(res.body.data.is_active).toBe(false);
            expect(res.body.data.name).toMatch(/^E2E Terminal Upd/);
        });

        test("can't update another store's card type", async () => {
            const res = await apiJson(api, "PUT", cardTypeUrl(fixtureIds().wallet.mainCardTypeId), { name: "Hijacked" });

            expect(res.status).toBe(403);
        });

        test("can't update another organization's card type", async () => {
            const res = await apiJson(api, "PUT", cardTypeUrl(fixtureIds().cardTypes["E2E McDonalds Card"]), { name: "Hijacked" });

            expect([403, 404]).toContain(res.status);
        });
    });

    test.describe("delete", () => {
        test("deletes an unused card type", async () => {
            const id = (await apiJson(api, "POST", cardTypesUrl(), { name: uniqueName("Del") })).body.data.id;

            const res = await apiJson(api, "DELETE", cardTypeUrl(id));

            expect(res.status).toBe(200);
            expect(res.body.message).toBe("Card type deleted.");
            expect((await apiJson(api, "GET", cardTypeUrl(id, "/money"))).status).toBe(404);
        });

        test("can't delete another store's card type", async () => {
            expect((await apiJson(api, "DELETE", cardTypeUrl(fixtureIds().wallet.mainCardTypeId))).status).toBe(403);
        });
    });

    test.describe("list (checkout)", () => {
        test("returns only this store's active card types", async () => {
            const inactiveId = (await apiJson(api, "POST", cardTypesUrl(), { name: uniqueName("Off") })).body.data.id;
            await apiJson(api, "PUT", cardTypeUrl(inactiveId), { is_active: false });

            const res = await apiJson(api, "GET", cardTypesUrl("/list"));

            expect(res.status).toBe(200);
            const ids = res.body.data.map((c) => c.id);
            expect(ids).toContain(store.cardTypeId);
            expect(ids).not.toContain(inactiveId);
            expect(ids).not.toContain(fixtureIds().wallet.mainCardTypeId);
        });
    });

    test.describe("money", () => {
        test("totals and history cover only paid sales on this terminal", async () => {
            const { cardSales } = store;

            const res = await apiJson(api, "GET", cardTypeUrl(store.cardTypeId, "/money"));

            expect(res.status).toBe(200);
            expect(res.body.today_total).toBe(cardSales.todayTotal);
            expect(res.body.yesterday_total).toBe(cardSales.yesterdayTotal);
            expect(res.body.history.total).toBe(cardSales.total);
            const invoices = res.body.history.data.map((s) => s.invoice_number);
            expect(invoices).not.toContain(cardSales.otherCardInvoice);
            expect(invoices).not.toContain(cardSales.pendingInvoice);
        });

        test("search matches invoice numbers", async () => {
            const res = await apiJson(api, "GET", cardTypeUrl(store.cardTypeId, `/money?search=${store.cardSales.recent[2].invoice}`));

            expect(res.body.history.data.map((s) => s.invoice_number)).toEqual([store.cardSales.recent[2].invoice]);
        });

        test("search treats % and _ literally", async () => {
            const res = await apiJson(api, "GET", cardTypeUrl(store.cardTypeId, "/money?search=%25"));

            expect(res.body.history.total).toBe(0);
        });

        test("paginates with per_page", async () => {
            const res = await apiJson(api, "GET", cardTypeUrl(store.cardTypeId, "/money?per_page=5&page=2"));

            expect(res.body.history.per_page).toBe(5);
            expect(res.body.history.current_page).toBe(2);
            expect(res.body.history.data).toHaveLength(5);
        });

        const cases = [
            { name: "page 0", query: "page=0", field: "page" },
            { name: "per_page over 100", query: "per_page=101", field: "per_page" },
            { name: "a search over 100 characters", query: `search=${"x".repeat(101)}`, field: "search" },
            { name: "a future start date", query: () => `history_from=${ymd(-2)}`, field: "history_from" },
            { name: "a future end date", query: () => `history_to=${ymd(-2)}`, field: "history_to" },
            { name: "an end date before the start date", query: () => `history_from=${ymd(1)}&history_to=${ymd(5)}`, field: "history_to" },
            { name: "an invalid date", query: "history_from=yesterday-ish", field: "history_from" },
        ];

        for (const { name, query, field } of cases) {
            test(`rejects ${name}`, async () => {
                const q = typeof query === "function" ? query() : query;
                expect422(await apiJson(api, "GET", cardTypeUrl(store.cardTypeId, `/money?${q}`)), field);
            });
        }

        test("can't read another store's terminal", async () => {
            expect((await apiJson(api, "GET", cardTypeUrl(fixtureIds().wallet.mainCardTypeId, "/money"))).status).toBe(403);
        });
    });

    test.describe("details page", () => {
        test("renders for this store's terminal", async () => {
            const res = await api.get(cardTypeUrl(store.cardTypeId, "/details"));

            expect(res.status()).toBe(200);
            expect(new URL(res.url()).pathname).toBe(cardTypeUrl(store.cardTypeId, "/details"));
        });

        test("rejects a future business date", async () => {
            expect422(await apiJson(api, "GET", cardTypeUrl(store.cardTypeId, `/details?business_date=${ymd(-2)}`)), "business_date");
        });

        test("can't open another store's terminal", async () => {
            expect((await apiJson(api, "GET", cardTypeUrl(fixtureIds().wallet.mainCardTypeId, "/details"))).status).toBe(403);
        });
    });
});

test.describe("Card terminals permissions", () => {
    test("guests are sent to login", async ({ page }) => {
        await page.goto(cardTypesUrl());
        await expect(page).toHaveURL(/\/login$/);
    });

    for (const account of ["admin", "manager"]) {
        test(`${account} can open the page`, async ({ serverAs }) => {
            const res = await (await serverAs(account)).get(cardTypesUrl());

            expect(res.status()).toBe(200);
            expect(new URL(res.url()).pathname).toBe(cardTypesUrl());
        });
    }

    test.describe("a cashier", () => {
        let api;

        test.beforeEach(async ({ serverAs }) => {
            api = await serverAs("worker-cashier");
        });

        test("is redirected away from the page", async () => {
            const res = await api.get(cardTypesUrl());

            expect(new URL(res.url()).pathname).not.toBe(cardTypesUrl());
        });

        test("can list card types for checkout", async () => {
            expect((await apiJson(api, "GET", cardTypesUrl("/list"))).status).toBe(200);
        });

        test("can't edit, delete or read a terminal's money", async () => {
            const id = fixtureIds().wallet.mainCardTypeId;

            expect((await apiJson(api, "PUT", cardTypeUrl(id), { name: "Cashier edit" })).status, "update").toBe(403);
            expect((await apiJson(api, "DELETE", cardTypeUrl(id))).status, "delete").toBe(403);
            expect((await apiJson(api, "GET", cardTypeUrl(id, "/money"))).status, "money").toBe(403);
            expect((await apiJson(api, "GET", cardTypeUrl(id, "/details"))).status, "details").toBe(403);
        });
    });

    test("an admin can manage a chosen store's terminals", async ({ serverAs }, testInfo) => {
        const admin = await serverAs("admin");
        const store = walletFixture(testInfo);

        const res = await apiJson(admin, "POST", cardTypesUrl(), { name: uniqueName("Admin"), location_id: store.locationId });

        expect(res.status).toBe(201);
        expect(res.body.data.location_id).toBe(store.locationId);
    });

    test("another organization's manager is denied", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", cardTypesUrl())).status).toBe(403);
        expect((await apiJson(api, "POST", cardTypesUrl(), { name: "E2E Terminal Intruder" })).status).toBe(403);
    });
});
