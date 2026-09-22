import { test, expect, apiJson } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";
import { workerNumber } from "../support/users.js";

const discountsUrl = (path = "") => `/domains/jollibee-corp/discounts${path}`;
const discountFixture = (testInfo) => fixtureIds().discountPage.workers[workerNumber(testInfo.parallelIndex)];
const uniqueName = (label) => `E2E New Disc ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const expect422 = (res, field) => {
    expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
    expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
};

const valid = (extra = {}) => ({ name: uniqueName("Api"), type: "amount", value: 10, scope: "order", ...extra });

async function findDiscounts(api, search, domain = "jollibee-corp") {
    const res = await api.get(`/domains/${domain}/discounts?search=${encodeURIComponent(search)}`);
    return (await responseProps(res)).items.data;
}

test.describe("Discounts API as an admin", () => {
    let admin;

    test.beforeEach(async ({ serverAs }) => {
        admin = await serverAs("admin");
    });

    const cases = [
        { name: "a missing name", patch: { name: undefined }, field: "name" },
        { name: "a name over 200 characters", patch: { name: "x".repeat(201) }, field: "name" },
        { name: "a missing type", patch: { type: undefined }, field: "type" },
        { name: "an unknown type", patch: { type: "bogo" }, field: "type" },
        { name: "a missing value", patch: { value: undefined }, field: "value" },
        { name: "a non-numeric value", patch: { value: "lots" }, field: "value" },
        { name: "a negative value", patch: { value: -10 }, field: "value" },
        { name: "a percentage over 100", patch: { type: "percentage", value: 150 }, field: "value" },
        { name: "a negative minimum order", patch: { min_order_amount: -1 }, field: "min_order_amount" },
        { name: "a missing scope", patch: { scope: undefined }, field: "scope" },
        { name: "an unknown scope", patch: { scope: "universe" }, field: "scope" },
        { name: "an invalid start date", patch: { start_date: "soon", end_date: "2030-01-01 00:00:00" }, field: "start_date" },
        { name: "a start date without an end date", patch: { start_date: "2027-01-01 00:00:00" }, field: "end_date" },
        { name: "an end date before the start date", patch: { start_date: "2027-05-01 00:00:00", end_date: "2027-04-01 00:00:00" }, field: "end_date" },
    ];

    for (const { name, patch, field } of cases) {
        test(`create rejects ${name}`, async () => {
            const body = valid(patch);
            for (const key of Object.keys(body)) if (body[key] === undefined) delete body[key];

            expect422(await apiJson(admin, "POST", discountsUrl(), body), field);
        });
    }

    test("creates an active discount in the admin's organization", async () => {
        const body = valid({ domain: "mcdonalds-corp" });

        const res = await apiJson(admin, "POST", discountsUrl(), body);

        expect([200, 302]).toContain(res.status);
        const [created] = await findDiscounts(admin, body.name);
        expect(created).toMatchObject({ name: body.name, domain: "jollibee-corp", is_active: true });
    });

    test("the older 'percent' type is accepted and saved as 'percentage'", async () => {
        const body = valid({ type: "percent", value: 20 });

        const res = await apiJson(admin, "POST", discountsUrl(), body);

        expect([200, 302]).toContain(res.status);
        expect((await findDiscounts(admin, body.name))[0].type).toBe("percentage");
    });

    test("updates a discount", async ({}, testInfo) => {
        const body = valid();
        await apiJson(admin, "POST", discountsUrl(), body);
        const [created] = await findDiscounts(admin, body.name);

        const res = await apiJson(admin, "PUT", discountsUrl(`/${created.id}`), { ...body, value: 42 });

        expect([200, 302]).toContain(res.status);
        expect(Number((await findDiscounts(admin, body.name))[0].value)).toBe(42);
        expect(discountFixture(testInfo)).toBeTruthy();
    });

    test("deletes a discount", async () => {
        const body = valid();
        await apiJson(admin, "POST", discountsUrl(), body);
        const [created] = await findDiscounts(admin, body.name);

        const res = await apiJson(admin, "DELETE", discountsUrl(`/${created.id}`));

        expect([200, 302]).toContain(res.status);
        expect(await findDiscounts(admin, body.name)).toHaveLength(0);
    });

    test("can't update another organization's discount", async ({ serverAs }) => {
        const { otherOrg } = fixtureIds().discountPage;

        const res = await apiJson(admin, "PUT", discountsUrl(`/${otherOrg.id}`), { name: otherOrg.name, type: "amount", value: 99, scope: "order" });

        expect([403, 404], `a Jollibee admin changing a McDonald's discount got ${res.status}`).toContain(res.status);
        const mc = await serverAs("mcManager");
        const [still] = await findDiscounts(mc, otherOrg.name, "mcdonalds-corp");
        expect(still, "still McDonald's discount").toBeTruthy();
        expect(Number(still.value)).toBe(otherOrg.value);
    });

    test("can't delete another organization's discount", async ({ serverAs }) => {
        const { otherOrg } = fixtureIds().discountPage;

        const res = await apiJson(admin, "DELETE", discountsUrl(`/${otherOrg.id}`));

        expect([403, 404], `a Jollibee admin deleting a McDonald's discount got ${res.status}`).toContain(res.status);
        const mc = await serverAs("mcManager");
        expect(await findDiscounts(mc, otherOrg.name, "mcdonalds-corp")).toHaveLength(1);
    });

    test("updating a missing discount is a clean not-found", async () => {
        const res = await apiJson(admin, "PUT", discountsUrl("/999999999"), valid());

        expect(res.status).not.toBe(500);
    });
});

test.describe("Discounts API permissions", () => {
    test("a manager can view but not create, update or delete", async ({ serverAs }, testInfo) => {
        const api = await serverAs("manager");
        const { amount } = discountFixture(testInfo);

        expect((await api.get(discountsUrl())).status()).toBe(200);
        expect((await apiJson(api, "POST", discountsUrl(), valid())).status, "create").toBe(403);
        expect((await apiJson(api, "PUT", discountsUrl(`/${amount.id}`), valid({ name: amount.name }))).status, "update").toBe(403);
        expect((await apiJson(api, "DELETE", discountsUrl(`/${amount.id}`))).status, "delete").toBe(403);
    });

    test("a cashier can view but not create, update or delete", async ({ serverAs }, testInfo) => {
        const api = await serverAs("worker-cashier");
        const { amount } = discountFixture(testInfo);

        expect((await api.get(discountsUrl())).status()).toBe(200);
        expect((await apiJson(api, "POST", discountsUrl(), valid())).status, "create").toBe(403);
        expect((await apiJson(api, "DELETE", discountsUrl(`/${amount.id}`))).status, "delete").toBe(403);
    });

    test("another organization's manager can't list Jollibee discounts", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", discountsUrl())).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(discountsUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
