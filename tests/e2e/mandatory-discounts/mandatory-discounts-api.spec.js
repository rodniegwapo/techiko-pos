import { test, expect, apiJson } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";
import { workerNumber } from "../support/users.js";

const mandatoryUrl = (path = "") => `/domains/jollibee-corp/mandatory-discounts${path}`;
const mandatoryFixture = (testInfo) => fixtureIds().mandatoryPage.workers[workerNumber(testInfo.parallelIndex)];
const uniqueName = (label) => `E2E New Mand ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const expect422 = (res, field) => {
    expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
    expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
};

const valid = (extra = {}) => ({ name: uniqueName("Api"), type: "percentage", value: 10, is_active: true, ...extra });

async function findMandatory(api, search, domain = "jollibee-corp") {
    const res = await api.get(`/domains/${domain}/mandatory-discounts?search=${encodeURIComponent(search)}`);
    return (await responseProps(res)).items.data;
}

test.describe("Mandatory discounts API as an admin", () => {
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
        { name: "a non-boolean status", patch: { is_active: "maybe" }, field: "is_active" },
        { name: "an unknown organization", patch: { domain: "no-such-org" }, field: "domain" },
    ];

    for (const { name, patch, field } of cases) {
        test(`create rejects ${name}`, async () => {
            const body = valid(patch);
            for (const key of Object.keys(body)) if (body[key] === undefined) delete body[key];

            expect422(await apiJson(admin, "POST", mandatoryUrl(), body), field);
        });
    }

    test("a value too large for the column is a validation error, not a crash", async () => {
        expect422(await apiJson(admin, "POST", mandatoryUrl(), valid({ type: "amount", value: 1e12 })), "value");
    });

    test("creates in the admin's organization even when another is sent", async () => {
        const body = valid({ domain: "mcdonalds-corp" });

        const res = await apiJson(admin, "POST", mandatoryUrl(), body);

        expect([200, 302]).toContain(res.status);
        const [created] = await findMandatory(admin, body.name);
        expect(created).toMatchObject({ name: body.name, domain: "jollibee-corp", type: "percentage", is_active: true });
    });

    test("creates an inactive mandatory discount", async () => {
        const body = valid({ type: "amount", value: 40, is_active: false });

        await apiJson(admin, "POST", mandatoryUrl(), body);

        const [created] = await findMandatory(admin, body.name);
        expect(created.is_active).toBe(false);
        expect(Number(created.value)).toBe(40);
    });

    test("updates a mandatory discount", async () => {
        const body = valid();
        await apiJson(admin, "POST", mandatoryUrl(), body);
        const [created] = await findMandatory(admin, body.name);

        const res = await apiJson(admin, "PUT", mandatoryUrl(`/${created.id}`), { ...body, value: 42, is_active: false });

        expect([200, 302]).toContain(res.status);
        const [saved] = await findMandatory(admin, body.name);
        expect(Number(saved.value)).toBe(42);
        expect(saved.is_active).toBe(false);
    });

    test("deletes a mandatory discount", async () => {
        const body = valid();
        await apiJson(admin, "POST", mandatoryUrl(), body);
        const [created] = await findMandatory(admin, body.name);

        const res = await apiJson(admin, "DELETE", mandatoryUrl(`/${created.id}`));

        expect([200, 302]).toContain(res.status);
        expect(await findMandatory(admin, body.name)).toHaveLength(0);
    });

    test("can't update another organization's mandatory discount", async ({ serverAs }) => {
        const { otherOrg } = fixtureIds().mandatoryPage;

        const res = await apiJson(admin, "PUT", mandatoryUrl(`/${otherOrg.id}`), { name: otherOrg.name, type: "amount", value: 99 });

        expect([403, 404], `a Jollibee admin changing a McDonald's mandatory discount got ${res.status}`).toContain(res.status);
        const mc = await serverAs("mcManager");
        const [still] = await findMandatory(mc, otherOrg.name, "mcdonalds-corp");
        expect(still, "still a McDonald's mandatory discount").toBeTruthy();
        expect(Number(still.value)).toBe(otherOrg.value);
    });

    test("can't delete another organization's mandatory discount", async ({ serverAs }) => {
        const { otherOrg } = fixtureIds().mandatoryPage;

        const res = await apiJson(admin, "DELETE", mandatoryUrl(`/${otherOrg.id}`));

        expect([403, 404], `a Jollibee admin deleting a McDonald's mandatory discount got ${res.status}`).toContain(res.status);
        const mc = await serverAs("mcManager");
        expect(await findMandatory(mc, otherOrg.name, "mcdonalds-corp")).toHaveLength(1);
    });

    test("updating a missing mandatory discount is a clean not-found", async () => {
        const res = await apiJson(admin, "PUT", mandatoryUrl("/999999999"), valid());

        expect(res.status).not.toBe(500);
    });

    test("an organization admin can't create a mandatory discount for another organization through the global route", async ({ serverAs }) => {
        const body = valid({ name: uniqueName("GlobalCreate"), domain: "mcdonalds-corp" });

        const res = await apiJson(admin, "POST", "/mandatory-discounts", body);

        const mc = await serverAs("mcManager");
        const created = await findMandatory(mc, body.name, "mcdonalds-corp");
        expect(created.map((i) => i.name), `a Jollibee admin's global create (${res.status}) landed in McDonald's`).not.toContain(body.name);
    });

    test("an organization admin can't read other organizations' mandatory discounts from the global list", async () => {
        const { otherOrg } = fixtureIds().mandatoryPage;

        const res = await admin.get(`/mandatory-discounts?search=${encodeURIComponent(otherOrg.name)}`);

        if (res.status() < 400) {
            const items = (await responseProps(res))?.items?.data ?? [];
            expect(items.map((i) => i.name), "McDonald's records in a Jollibee admin's list").not.toContain(otherOrg.name);
        }
    });
});

test.describe("Mandatory discounts API permissions", () => {
    test("a manager can view but not create, update or delete", async ({ serverAs }, testInfo) => {
        const api = await serverAs("manager");
        const { percent } = mandatoryFixture(testInfo);

        expect((await api.get(mandatoryUrl())).status()).toBe(200);
        expect((await apiJson(api, "POST", mandatoryUrl(), valid())).status, "create").toBe(403);
        expect((await apiJson(api, "PUT", mandatoryUrl(`/${percent.id}`), valid({ name: percent.name }))).status, "update").toBe(403);
        expect((await apiJson(api, "DELETE", mandatoryUrl(`/${percent.id}`))).status, "delete").toBe(403);
    });

    test("a cashier can't open or change mandatory discounts", async ({ serverAs }, testInfo) => {
        const api = await serverAs("worker-cashier");
        const { percent } = mandatoryFixture(testInfo);

        expect((await apiJson(api, "GET", mandatoryUrl())).status, "view").toBe(403);
        expect((await apiJson(api, "POST", mandatoryUrl(), valid())).status, "create").toBe(403);
        expect((await apiJson(api, "DELETE", mandatoryUrl(`/${percent.id}`))).status, "delete").toBe(403);
    });

    test("another organization's manager can't list Jollibee mandatory discounts", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", mandatoryUrl())).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(mandatoryUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
