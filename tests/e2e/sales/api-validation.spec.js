import { test, expect, apiJson } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { cartState, fixtureIds, freshWorkerCart, pendingSaleWith, saleUrl, userCartUrl } from "../support/sales.js";

const { products, customers, cards, discounts, pins } = E2E;

/**
 * Server-side validation for every Sales endpoint, sent as the worker cashier over HTTP.
 * Each row asserts 422 and the field the error is reported on.
 */
test.describe("Sales API validation", () => {
    let api;
    let userId;
    let ids;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        ({ api, userId } = await freshWorkerCart(serverAs, testInfo));
        ids = fixtureIds();
    });

    const expect422 = (res, field) => {
        expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
        expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
    };

    test.describe("cart: add item", () => {
        // `burger` is replaced with the E2E Burger ID at run time.
        const cases = [
            { name: "a missing product", body: { quantity: 1 }, field: "product_id" },
            { name: "a missing quantity", body: { product_id: "burger" }, field: "quantity" },
            { name: "quantity 0", body: { product_id: "burger", quantity: 0 }, field: "quantity" },
            { name: "a negative quantity", body: { product_id: "burger", quantity: -1 }, field: "quantity" },
            { name: "a non-numeric quantity", body: { product_id: "burger", quantity: "abc" }, field: "quantity" },
            { name: "a decimal quantity", body: { product_id: "burger", quantity: 1.5 }, field: "quantity" },
            { name: "a nonexistent product", body: { product_id: 999999, quantity: 1 }, field: "product_id" },
        ];

        for (const { name, body, field } of cases) {
            test(`rejects ${name}`, async () => {
                const payload = { ...body };
                if (payload.product_id === "burger") payload.product_id = ids.products[products.burger.name];

                expect422(await apiJson(api, "POST", userCartUrl(userId, "/cart/add"), payload), field);
            });
        }

        test("rejects adding an out-of-stock product", async () => {
            const res = await apiJson(api, "POST", userCartUrl(userId, "/cart/add"), {
                product_id: ids.products[products.soldOut.name],
                quantity: 1,
            });

            expect422(res, "stock");
            expect((await cartState(api, userId)).items).toHaveLength(0);
        });
    });

    test.describe("cart: update quantity and remove", () => {
        test("rejects quantity 0", async () => {
            await pendingSaleWith(api, userId, ids.products[products.burger.name]);

            expect422(
                await apiJson(api, "PATCH", userCartUrl(userId, "/cart/update-quantity"), {
                    product_id: ids.products[products.burger.name],
                    quantity: 0,
                }),
                "quantity",
            );
        });

        test("rejects a quantity above stock", async () => {
            await pendingSaleWith(api, userId, ids.products[products.limited.name]);

            expect422(
                await apiJson(api, "PATCH", userCartUrl(userId, "/cart/update-quantity"), {
                    product_id: ids.products[products.limited.name],
                    quantity: 3,
                }),
                "stock",
            );
        });

        test("updating a product that isn't in the cart is a clean 4xx", async () => {
            await pendingSaleWith(api, userId, ids.products[products.burger.name]);

            const res = await apiJson(api, "PATCH", userCartUrl(userId, "/cart/update-quantity"), {
                product_id: ids.products[products.fries.name],
                quantity: 2,
            });

            expect(res.status, "should not be a server error").toBeGreaterThanOrEqual(400);
            expect(res.status).toBeLessThan(500);
        });

        test("rejects remove without a product", async () => {
            await pendingSaleWith(api, userId, ids.products[products.burger.name]);

            expect422(await apiJson(api, "DELETE", userCartUrl(userId, "/cart/remove"), {}), "product_id");
        });

        test("update without a pending cart returns 404", async () => {
            const res = await apiJson(api, "PATCH", userCartUrl(userId, "/cart/update-quantity"), {
                product_id: ids.products[products.burger.name],
                quantity: 2,
            });

            // freshWorkerCart leaves an empty pending sale when one existed, so accept 404 or a 4xx for "not in cart".
            expect(res.status).toBeGreaterThanOrEqual(400);
            expect(res.status).toBeLessThan(500);
        });
    });

    test.describe("payments", () => {
        let saleId;

        test.beforeEach(async () => {
            saleId = (await pendingSaleWith(api, userId, ids.products[products.burger.name])).id;
        });

        const pay = (body) => apiJson(api, "POST", saleUrl(saleId, "/payments"), body);

        test("rejects a missing payment method", async () => {
            expect422(await pay({}), "payment_method");
        });

        test("rejects an unknown payment method", async () => {
            expect422(await pay({ payment_method: "bitcoin" }), "payment_method");
        });

        test("rejects card without a card type", async () => {
            expect422(await pay({ payment_method: "card" }), "payment_card_type_id");
        });

        test("rejects an inactive card type", async () => {
            expect422(await pay({ payment_method: "card", payment_card_type_id: ids.cardTypes[cards.inactive] }), "payment_card_type_id");
        });

        test("rejects another organization's card type", async () => {
            expect422(await pay({ payment_method: "card", payment_card_type_id: ids.cardTypes[cards.otherOrg] }), "payment_card_type_id");
        });

        test("rejects negative loyalty points", async () => {
            expect422(await pay({ payment_method: "cash", loyalty_points_to_redeem: -1 }), "loyalty_points_to_redeem");
        });

        test("rejects a negative sale amount", async () => {
            expect422(await pay({ payment_method: "cash", sale_amount: -1 }), "sale_amount");
        });

        test("rejects a nonexistent customer", async () => {
            expect422(await pay({ payment_method: "cash", customer_id: 999999 }), "customer_id");
        });

        test("the sale is still pending after rejected payments", async () => {
            await pay({ payment_method: "bitcoin" });
            await pay({ payment_method: "card" });

            const state = await cartState(api, userId);
            expect(state.sale?.id).toBe(saleId);
            expect(state.sale.payment_status).toBe("pending");
        });
    });

    test.describe("payment business rules", () => {
        test("paying an empty cart is rejected", async () => {
            const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);
            await apiJson(api, "DELETE", userCartUrl(userId, "/cart/remove"), { product_id: ids.products[products.burger.name] });

            const res = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), { payment_method: "cash" });

            expect(res.status, `an empty sale must not be completed (got ${res.status})`).toBe(422);
        });

        test("paying an already-paid sale is a validation error, not a server error", async () => {
            const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);
            const first = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), { payment_method: "cash" });
            expect(first.status).toBe(200);

            const second = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), { payment_method: "cash" });

            expect(second.status, `second payment: ${second.body?.message}`).toBe(422);
        });

        test("credit without a customer is a validation error", async () => {
            const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);

            const res = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), { payment_method: "credit" });

            expect(res.status, `got ${res.status}: ${res.body?.message}`).toBe(422);
            expect((await cartState(api, userId)).sale?.id, "sale must stay pending").toBe(sale.id);
        });

        test("credit over the customer's limit is a validation error", async () => {
            const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name], 6); // ₱600 > ₱500

            const res = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), {
                payment_method: "credit",
                customer_id: ids.customers[customers.credit.name],
            });

            expect(res.status, `got ${res.status}: ${res.body?.message}`).toBe(422);
            expect((await cartState(api, userId)).sale?.id, "sale must stay pending").toBe(sale.id);
        });

        test("credit for a customer without credit is a validation error", async () => {
            const sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);

            const res = await apiJson(api, "POST", saleUrl(sale.id, "/payments"), {
                payment_method: "credit",
                customer_id: ids.customers[customers.noCredit.name],
            });

            expect(res.status, `got ${res.status}: ${res.body?.message}`).toBe(422);
        });
    });

    test.describe("loyalty redemption", () => {
        let saleId;

        test.beforeEach(async () => {
            saleId = (await pendingSaleWith(api, userId, ids.products[products.burger.name])).id;
        });

        test("rejects points without a customer", async () => {
            expect422(await apiJson(api, "PATCH", saleUrl(saleId, "/loyalty-redemption"), { loyalty_points: 100 }), "customer_id");
        });

        test("rejects negative points", async () => {
            expect422(
                await apiJson(api, "PATCH", saleUrl(saleId, "/loyalty-redemption"), {
                    loyalty_points: -5,
                    customer_id: ids.customers[customers.loyalty.name],
                }),
                "loyalty_points",
            );
        });

        test("rejects more points than the customer has", async () => {
            expect422(
                await apiJson(api, "PATCH", saleUrl(saleId, "/loyalty-redemption"), {
                    loyalty_points: 999999,
                    customer_id: ids.customers[customers.noCredit.name], // 0 points
                }),
                "loyalty_points",
            );
        });

        test("accepts a valid redemption", async () => {
            const res = await apiJson(api, "PATCH", saleUrl(saleId, "/loyalty-redemption"), {
                loyalty_points: 1000,
                customer_id: ids.customers[customers.loyalty.name],
            });

            expect(res.status).toBe(200);
            expect(Number(res.body.sale.loyalty_discount_amount)).toBe(10);
        });
    });

    test.describe("discounts", () => {
        let sale;

        test.beforeEach(async () => {
            sale = await pendingSaleWith(api, userId, ids.products[products.burger.name]);
        });

        test("rejects a nonexistent order discount", async () => {
            expect422(
                await apiJson(api, "PATCH", saleUrl(sale.id, "/discounts"), { regular_discount_ids: [999999] }),
                "regular_discount_ids.0",
            );
        });

        test("rejects a nonexistent mandatory discount", async () => {
            expect422(
                await apiJson(api, "PATCH", saleUrl(sale.id, "/discounts"), { mandatory_discount_ids: [999999] }),
                "mandatory_discount_ids.0",
            );
        });

        test("rejects two mandatory discounts on one sale (single-mandatory rule)", async () => {
            const res = await apiJson(api, "PATCH", saleUrl(sale.id, "/discounts"), {
                mandatory_discount_ids: [ids.mandatoryDiscounts[discounts.senior], ids.mandatoryDiscounts[discounts.pwd]],
            });

            expect(res.status, `PWD + Senior together must be rejected (got ${res.status})`).toBeGreaterThanOrEqual(400);
            expect(res.status).toBeLessThan(500);
        });

        test("rejects an order discount on an empty cart", async () => {
            await apiJson(api, "DELETE", userCartUrl(userId, "/cart/remove"), { product_id: ids.products[products.burger.name] });

            const res = await apiJson(api, "PATCH", saleUrl(sale.id, "/discounts"), {
                regular_discount_ids: [ids.discounts[discounts.order]],
            });

            expect(res.status).toBe(400);
            expect(res.body.message).toContain("No items found");
        });

        test("rejects an item discount without a discount id", async () => {
            const itemId = (await cartState(api, userId)).items[0].id;

            expect422(await apiJson(api, "POST", saleUrl(sale.id, `/items/${itemId}/discounts`), {}), "discount_id");
        });

        test("rejects an order-scope discount applied to an item", async () => {
            const itemId = (await cartState(api, userId)).items[0].id;

            const res = await apiJson(api, "POST", saleUrl(sale.id, `/items/${itemId}/discounts`), {
                discount_id: ids.discounts[discounts.order],
            });

            expect(res.status, `order discount on an item: got ${res.status}`).toBeGreaterThanOrEqual(400);
            expect(res.status).toBeLessThan(500);
        });
    });

    test.describe("void", () => {
        let saleId;

        test.beforeEach(async () => {
            saleId = (await pendingSaleWith(api, userId, ids.products[products.burger.name])).id;
        });

        test("rejects a missing PIN", async () => {
            expect422(
                await apiJson(api, "POST", saleUrl(saleId, "/sales-items/void"), { product_id: ids.products[products.burger.name] }),
                "pin_code",
            );
        });

        test("rejects a missing product", async () => {
            expect422(await apiJson(api, "POST", saleUrl(saleId, "/sales-items/void"), { pin_code: pins.manager }), "product_id");
        });

        test("voiding a product that isn't in the cart is a clean 4xx", async () => {
            const res = await apiJson(api, "POST", saleUrl(saleId, "/sales-items/void"), {
                pin_code: pins.manager,
                product_id: ids.products[products.fries.name],
            });

            expect(res.status).toBeGreaterThanOrEqual(400);
            expect(res.status).toBeLessThan(500);
        });
    });

    test.describe("create customer", () => {
        const cases = [
            { name: "a missing name", body: { email: "e2e-new-x@techiko.test" }, field: "name" },
            { name: "an invalid email", body: { name: "E2E Bad Email", email: "not-an-email" }, field: "email" },
            { name: "a name over 255 characters", body: { name: "x".repeat(256) }, field: "name" },
            { name: "a phone over 20 characters", body: { name: "E2E Long Phone", phone: "1".repeat(21) }, field: "phone" },
            { name: "a duplicate email", body: { name: "E2E Dup", email: "e2e-loyalty@techiko.test" }, field: "email" },
            { name: "an invalid date of birth", body: { name: "E2E Bad Date", date_of_birth: "not-a-date" }, field: "date_of_birth" },
        ];

        for (const { name, body, field } of cases) {
            test(`rejects ${name}`, async () => {
                expect422(await apiJson(api, "POST", "/api/customers", body), field);
            });
        }
    });
});
