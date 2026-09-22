import { test, expect, apiJson } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";
import { workerNumber } from "../support/users.js";

const categoriesUrl = (path = "") => `/domains/jollibee-corp/categories${path}`;
const categoryFixture = (testInfo) => fixtureIds().categories.workers[workerNumber(testInfo.parallelIndex)];
const uniqueName = (label) => `E2E New Cat ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const expect422 = (res, field) => {
    expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
    expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
};

/** Categories matching a search, read from the page props. */
async function findCategories(api, search) {
    const res = await api.get(`${categoriesUrl()}?search=${encodeURIComponent(search)}`);
    return (await responseProps(res)).items.data;
}

test.describe("Categories API as a manager", () => {
    let api;
    let fixture;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        api = await serverAs("wallet-manager");
        fixture = categoryFixture(testInfo);
    });

    const cases = [
        { name: "a missing name", body: { description: "x" }, field: "name" },
        { name: "an empty name", body: { name: "" }, field: "name" },
        { name: "a name over 255 characters", body: { name: "x".repeat(256) }, field: "name" },
        { name: "a non-text name", body: { name: ["a", "b"] }, field: "name" },
        { name: "a non-text description", body: { name: "E2E New Cat Desc", description: { a: 1 } }, field: "description" },
    ];

    for (const { name, body, field } of cases) {
        test(`create rejects ${name}`, async () => {
            expect422(await apiJson(api, "POST", categoriesUrl(), body), field);
        });
    }

    test("creates a category in the manager's organization", async () => {
        const name = uniqueName("Api");

        const res = await apiJson(api, "POST", categoriesUrl(), { name, description: "via API", domain: "mcdonalds-corp" });

        expect([200, 302]).toContain(res.status);
        const [created] = (await findCategories(api, name)).filter((c) => c.name === name);
        expect(created, "listed in Jollibee").toBeTruthy();
        expect(created.domain, "a domain in the request is ignored").toBe("jollibee-corp");
    });

    test("the name is trimmed", async () => {
        const name = uniqueName("Trim");

        await apiJson(api, "POST", categoriesUrl(), { name: `   ${name}   ` });

        expect((await findCategories(api, name)).map((c) => c.name)).toContain(name);
    });

    test("rejects a name already used in the organization", async () => {
        // A throwaway name: if the duplicate is wrongly accepted it must not shadow a seeded category.
        const name = uniqueName("DupApi");
        await apiJson(api, "POST", categoriesUrl(), { name });

        expect422(await apiJson(api, "POST", categoriesUrl(), { name }), "name");
    });

    test("the same name is allowed in another organization", async ({ serverAs }) => {
        const mc = await serverAs("mcManager");
        const name = uniqueName("Shared");
        await apiJson(api, "POST", categoriesUrl(), { name });

        const res = await apiJson(mc, "POST", "/domains/mcdonalds-corp/categories", { name });

        expect([200, 302]).toContain(res.status);
    });

    test("update validates the name", async () => {
        expect422(await apiJson(api, "PUT", categoriesUrl(`/${fixture.plain.id}`), { name: "" }), "name");
        expect422(await apiJson(api, "PUT", categoriesUrl(`/${fixture.plain.id}`), { name: "x".repeat(256) }), "name");
    });

    test("update rejects renaming to another category's name", async () => {
        // Throwaway categories: if the rename wrongly succeeds it must not disturb the seeded ones.
        const first = uniqueName("RenameA");
        const second = uniqueName("RenameB");
        await apiJson(api, "POST", categoriesUrl(), { name: first });
        await apiJson(api, "POST", categoriesUrl(), { name: second });
        const [firstCategory] = await findCategories(api, first);

        expect422(await apiJson(api, "PUT", categoriesUrl(`/${firstCategory.id}`), { name: second }), "name");
    });

    test("update keeps its own name", async () => {
        const res = await apiJson(api, "PUT", categoriesUrl(`/${fixture.plain.id}`), { name: fixture.plain.name, description: fixture.plain.description });

        expect([200, 302]).toContain(res.status);
    });

    test("can't update another organization's category", async () => {
        const res = await apiJson(api, "PUT", categoriesUrl(`/${fixtureIds().categories.otherOrgCategoryId}`), { name: "Hijacked" });

        expect(res.status).toBe(403);
    });

    test("a manager can't delete categories", async () => {
        expect((await apiJson(api, "DELETE", categoriesUrl(`/${fixture.plain.id}`))).status).toBe(403);
        expect((await findCategories(api, fixture.plain.name)).length).toBe(1);
    });
});

test.describe("Categories API as an admin", () => {
    let admin;
    let fixture;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        admin = await serverAs("admin");
        fixture = categoryFixture(testInfo);
    });

    test("deletes an unused category", async () => {
        const name = uniqueName("AdminDel");
        await apiJson(admin, "POST", categoriesUrl(), { name });
        const [created] = await findCategories(admin, name);

        const res = await apiJson(admin, "DELETE", categoriesUrl(`/${created.id}`));

        expect([200, 302]).toContain(res.status);
        expect(await findCategories(admin, name)).toHaveLength(0);
    });

    test("won't delete a category that has products", async () => {
        await apiJson(admin, "DELETE", categoriesUrl(`/${fixture.inUse.id}`));

        expect((await findCategories(admin, fixture.inUse.name)).map((c) => c.id)).toContain(fixture.inUse.id);
    });

    test("can't delete another organization's category", async () => {
        expect((await apiJson(admin, "DELETE", categoriesUrl(`/${fixtureIds().categories.otherOrgCategoryId}`))).status).toBe(403);
    });
});

test.describe("Categories API permissions", () => {
    test("a cashier can't list, create, update or delete categories", async ({ serverAs }, testInfo) => {
        const api = await serverAs("worker-cashier");
        const { plain } = categoryFixture(testInfo);

        expect((await apiJson(api, "GET", categoriesUrl())).status, "list").toBe(403);
        expect((await apiJson(api, "POST", categoriesUrl(), { name: uniqueName("Cashier") })).status, "create").toBe(403);
        expect((await apiJson(api, "PUT", categoriesUrl(`/${plain.id}`), { name: "Cashier edit" })).status, "update").toBe(403);
        expect((await apiJson(api, "DELETE", categoriesUrl(`/${plain.id}`))).status, "delete").toBe(403);
    });

    test("another organization's manager can't read or change Jollibee categories", async ({ serverAs }, testInfo) => {
        const api = await serverAs("mcManager");
        const { plain } = categoryFixture(testInfo);

        expect((await apiJson(api, "GET", categoriesUrl())).status).toBe(403);
        expect((await apiJson(api, "PUT", categoriesUrl(`/${plain.id}`), { name: "Intruder" })).status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(categoriesUrl());
        await expect(page).toHaveURL(/\/login$/);
    });
});
