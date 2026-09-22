import { test, expect, apiJson } from "../support/fixtures.js";
import { E2E, USERS } from "../support/users.js";
import { cartState, clearCart, fixtureIds, freshWorkerCart, pendingSaleWith, saleUrl, salesUrl, userCartUrl } from "../support/sales.js";

const { products, customers, cards, discounts, pins } = E2E;

/** Denied means a 4xx that isn't validation noise; 404 (not found in this org) and 403 both count. */
const expectDenied = (res, what) => {
    expect([401, 403, 404], `${what} should be denied, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 160)}`).toContain(res.status);
};

test.describe("Sales permissions", () => {
    test("guests can't use the cart API", async ({ playwright, baseURL }) => {
        const api = await playwright.request.newContext({ baseURL });
        const ids = fixtureIds();

        const res = await apiJson(api, "POST", userCartUrl(ids.users["cashier1@jollibee-corp.com"], "/cart/add"), {
            product_id: ids.products[products.burger.name],
            quantity: 1,
        });

        expect([401, 419, 302], `guest request got ${res.status}`).toContain(res.status);
        await api.dispose();
    });

    test("a cashier can't open card terminal management", async ({ serverAs }) => {
        const api = await serverAs("worker-cashier");
        const res = await api.get(`/domains/${E2E.domain}/payment-card-types`);

        // Opened directly, with no page to go back to, the refusal is a 403 (a redirect back could loop).
        expect(res.status(), "cashier should be refused").toBe(403);
    });

    for (const account of ["admin", "manager"]) {
        test(`${account} can open card terminal management`, async ({ serverAs }) => {
            const api = await serverAs(account);
            const res = await api.get(`/domains/${E2E.domain}/payment-card-types`);

            expect(res.status()).toBe(200);
            expect(new URL(res.url()).pathname).toBe(`/domains/${E2E.domain}/payment-card-types`);
        });
    }

    test("a user without the dashboard permission can still sell", async ({ serverAs }) => {
        const api = await serverAs("noDashboard");
        const res = await api.get(salesUrl());

        expect(res.status()).toBe(200);
        expect(new URL(res.url()).pathname).toBe(salesUrl());
    });
});

/**
 * A McDonald's cashier's pending sale, targeted by a Jollibee cashier through Jollibee URLs.
 * Runs in order in one worker: the McDonald's cashier has a single cart.
 */
test.describe("Another organization's sale", () => {
    test.describe.configure({ mode: "default" });

    let ids;
    let mcApi;
    let mcUserId;
    let mcSale;
    let jbApi;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        ids = fixtureIds();
        mcUserId = ids.users["cashier1@mcdonalds-corp.com"];
        mcApi = await serverAs("mcCashier");
        await clearCart(mcApi, mcUserId, ids.otherOrg.domain);
        mcSale = await pendingSaleWith(mcApi, mcUserId, ids.otherOrg.productId, 1, ids.otherOrg.domain);
        ({ api: jbApi } = await freshWorkerCart(serverAs, testInfo));
    });

    /** The McDonald's cart must look exactly as it did before the attempt. */
    async function expectMcSaleUnchanged() {
        const state = await cartState(mcApi, mcUserId, ids.otherOrg.domain);
        expect(state.sale?.id, "McDonald's sale still pending").toBe(mcSale.id);
        expect(state.items.map((i) => [i.product_id, i.quantity]), "McDonald's cart lines unchanged").toEqual([[ids.otherOrg.productId, 1]]);
        expect(Number(state.totals.discount_amount), "no discount added").toBe(0);
        expect(state.sale.customer_id, "no customer assigned").toBeNull();
    }

    const attempts = [
        { name: "read its cart state", method: "GET", path: "/cart/state" },
        { name: "find its sale items", method: "GET", path: () => `/find-sale-item?product_id=${ids.otherOrg.productId}` },
        { name: "read its discounts", method: "GET", path: "/discounts" },
        { name: "add an item to it", method: "POST", path: "/cart/add", body: () => ({ product_id: ids.products[products.burger.name], quantity: 1 }) },
        { name: "change its quantities", method: "PATCH", path: "/cart/update-quantity", body: () => ({ product_id: ids.otherOrg.productId, quantity: 5 }) },
        { name: "remove its items", method: "DELETE", path: "/cart/remove", body: () => ({ product_id: ids.otherOrg.productId }) },
        { name: "void its items with a Jollibee manager PIN", method: "POST", path: "/sales-items/void", body: () => ({ product_id: ids.otherOrg.productId, pin_code: pins.manager }) },
        { name: "assign a customer to it", method: "POST", path: "/assign-customer", body: () => ({ customer_id: ids.customers[customers.loyalty.name] }) },
        { name: "apply order discounts to it", method: "PATCH", path: "/discounts", body: () => ({ regular_discount_ids: [ids.discounts[discounts.order]] }) },
        { name: "remove its discounts", method: "DELETE", path: "/discounts" },
        { name: "change its loyalty redemption", method: "PATCH", path: "/loyalty-redemption", body: () => ({ loyalty_points: 0 }) },
        { name: "take payment for it", method: "POST", path: "/payments", body: () => ({ payment_method: "cash" }) },
    ];

    for (const { name, method, path, body } of attempts) {
        test(`a Jollibee cashier can't ${name}`, async () => {
            const url = saleUrl(mcSale.id, typeof path === "function" ? path() : path);

            const res = await apiJson(jbApi, method, url, body?.());

            expectDenied(res, `${method} ${url}`);
            await expectMcSaleUnchanged();
        });
    }

    test("a Jollibee cashier can't create a sale for a McDonald's user", async () => {
        const res = await apiJson(jbApi, "POST", userCartUrl(mcUserId), {});

        expectDenied(res, "POST users/{mcdonalds cashier}/sales");
    });
});

test.describe("Records from another organization", () => {
    let ids;
    let api;
    let userId;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        ids = fixtureIds();
        ({ api, userId } = await freshWorkerCart(serverAs, testInfo));
    });

    test.afterEach(async () => {
        await clearCart(api, userId);
    });

    test("can't add another organization's product to the cart", async () => {
        const res = await apiJson(api, "POST", userCartUrl(userId, "/cart/add"), { product_id: ids.otherOrg.productId, quantity: 1 });

        expect([403, 404, 422], `adding a McDonald's product got ${res.status}`).toContain(res.status);
        expect((await cartState(api, userId)).items.map((i) => i.product_id)).not.toContain(ids.otherOrg.productId);
    });

    test("can't assign another organization's customer to a sale", async () => {
        const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);

        const res = await apiJson(api, "POST", saleUrl(sale.id, "/assign-customer"), { customer_id: ids.otherOrg.customerId });

        expect([403, 404, 422], `assigning a McDonald's customer got ${res.status}`).toContain(res.status);
    });

    test("can't pay with another organization's customer", async () => {
        const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);

        const res = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), { payment_method: "cash", customer_id: ids.otherOrg.customerId });

        expect([403, 404, 422], `paying with a McDonald's customer got ${res.status}`).toContain(res.status);
    });

    test("can't pay with another organization's card type", async () => {
        const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);

        const res = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), {
            payment_method: "card",
            payment_card_type_id: ids.cardTypes[cards.otherOrg],
        });

        expect(res.status).toBe(422);
    });

    test("can't apply another organization's discount", async () => {
        const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);

        const res = await apiJson(api, "PATCH", saleUrl(sale.id, "/discounts"), { regular_discount_ids: [ids.otherOrg.discountId] });

        expect([400, 403, 404, 422], `applying a McDonald's discount got ${res.status}`).toContain(res.status);
    });

    test("a customer created by a cashier always belongs to the cashier's organization", async () => {
        const res = await apiJson(api, "POST", "/api/customers", {
            name: "E2E Domain Override",
            email: `e2e-new-domain-${Date.now()}@techiko.test`,
            domain: ids.otherOrg.domain,
        });

        expect(res.status).toBe(200);
        expect(res.body.customer.domain).toBe(E2E.domain);
    });

    test("customer search doesn't return another organization's customers", async () => {
        const res = await apiJson(api, "GET", "/api/customers/search?q=example.com");

        expect(res.status).toBe(200);
        for (const found of res.body) {
            expect(found.domain, `${found.name} is from ${found.domain}`).toBe(E2E.domain);
        }
    });
});

test.describe("Other users' carts in the same organization", () => {
    test("a cashier editing another cashier's cart", async ({ serverAs, observe }, testInfo) => {
        const ids = fixtureIds();
        const { api } = await freshWorkerCart(serverAs, testInfo);
        const victimId = ids.users["cashier1@jollibee-corp.com"];

        const res = await apiJson(api, "POST", userCartUrl(victimId, "/cart/add"), {
            product_id: ids.products[products.probe.name],
            quantity: 1,
        });

        if (res.status === 200) {
            observe(
                `A cashier can add items to another cashier's cart (POST users/${victimId}/sales/cart/add returned 200, sale owner ${res.body.sale?.user_id}). Decide whether this should be allowed.`,
            );
            const victim = await serverAs("cashier");
            await clearCart(victim, victimId);
        } else {
            expect([403, 404]).toContain(res.status);
        }
    });

    test("an admin removing an item from their own cart only touches their own sale", async ({ serverAs }) => {
        const ids = fixtureIds();
        const adminId = ids.users[USERS.admin.email];
        const admin = await serverAs("admin");

        // The admin has no pending cart (the seeder clears it), so there is nothing of theirs to remove from.
        const res = await apiJson(admin, "DELETE", userCartUrl(adminId, "/cart/remove"), {
            product_id: ids.products[products.probe.name],
        });

        if (res.status === 200) {
            expect(res.body.sale.user_id, "the admin's remove call edited another user's pending sale").toBe(adminId);
        } else {
            expect(res.status).toBe(404);
        }
    });
});
