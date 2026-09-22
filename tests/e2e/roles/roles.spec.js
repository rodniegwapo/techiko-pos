import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps, responseProps } from "../support/inertia.js";

/**
 * The roles page (Role Management) at /roles.
 *
 * Roles are global: one set shared by every organization, which is why the page sits outside the
 * /domains prefix and why no organization role is granted any roles.* permission — a super user is
 * the only one who can reach it (see RolePermissionSeeder).
 *
 * The five seeded roles are the ones the application depends on by name (super admin, admin,
 * manager, supervisor, cashier), so nothing here renames or deletes them. Tests that need a role
 * to change make their own and remove it afterwards.
 */

const rolesPath = "/roles";
const roleUrl = (id, path = "") => `${rolesPath}/${id}${path}`;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });

/** Roles this test made; removed again in afterEach. */
const made = [];

const uniqueName = () => `e2e role ${Math.random().toString(36).slice(2, 8)}`;

async function openRoles(page) {
    await page.goto(rolesPath);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await page.getByPlaceholder("Search roles by name...").fill(text);
    await found;
}

/** The roles the page lists, read fresh. */
async function rolesNow(api) {
    return (await responseProps(await api.get(rolesPath))).roles.data;
}

/** A role of this installation, found by name. */
const roleNamed = (roles, name) => roles.find((r) => r.name === name);

/** Makes a role over HTTP and remembers it for cleanup. */
async function makeRole(api, { name = uniqueName(), level = 42, description = "E2E role", permissions = [] } = {}) {
    const res = await apiJson(api, "POST", rolesPath, { name, level, description, permissions });
    expect(res.status, `create role ${name}`).toBeLessThan(400);

    const created = roleNamed(await rolesNow(api), name);
    expect(created, `${name} is listed after creating it`).toBeTruthy();
    made.push(created.id);

    return created;
}

test.afterEach(async ({ serverAs }) => {
    if (! made.length) {
        return;
    }
    const api = await serverAs("super");
    while (made.length) {
        await apiJson(api, "DELETE", roleUrl(made.pop())).catch(() => {});
    }
});

test.describe("Roles list (super user)", () => {
    test.use({ account: "super" });

    test("lists the installation's roles with what each can do and who holds it", async ({ page }) => {
        await openRoles(page);

        const { roles } = await pageProps(page);
        expect(roles.data.length).toBeGreaterThan(0);
        await expect(rows(page)).toHaveCount(roles.data.length);

        const cashier = rowWith(page, "cashier");
        await expect(cashier).toHaveCount(1);
        const held = roleNamed(roles.data, "cashier");
        await expect(cashier, "how many permissions it carries").toContainText(String(held.permissions.length));
    });

    test("super admin is kept off the list", async ({ page }) => {
        await openRoles(page);

        // The role that can do everything isn't offered for editing alongside the rest.
        const { roles } = await pageProps(page);
        expect(roles.data.map((r) => r.name)).not.toContain("super admin");
        await expect(rowWith(page, "super admin")).toHaveCount(0);
    });

    test("search narrows the list to the roles that match", async ({ page }) => {
        await openRoles(page);

        await search(page, "manager");

        await expect(rowWith(page, "manager")).toHaveCount(1);
        await expect(rowWith(page, "cashier")).toHaveCount(0);
    });

    test("a search with no match leaves the list empty", async ({ page }) => {
        await openRoles(page);

        await search(page, "e2e-no-such-role-anywhere");

        await expect(rows(page)).toHaveCount(0);
    });

    test("the page offers adding a role and the permission matrix", async ({ page }) => {
        await openRoles(page);

        await expect(page.getByRole("button", { name: "Add Role" })).toBeVisible();
        await expect(page.getByRole("button", { name: "Permission Matrix" })).toBeVisible();
    });

    test("the permission matrix shows every role against the permissions", async ({ page }) => {
        await openRoles(page);

        await page.getByRole("button", { name: "Permission Matrix" }).click();

        await expect(page).toHaveURL(/roles-permissions\/matrix$/);
        const { roles, permissions } = await pageProps(page);
        expect(roles.data.length, "every role, super admin included").toBeGreaterThan(0);
        expect(Object.keys(permissions).length, "grouped by what they cover").toBeGreaterThan(0);
    });
});

test.describe("Adding a role (super user)", () => {
    test.use({ account: "super" });
    // These add roles, whose name has to be free. One at a time, in order.
    test.describe.configure({ mode: "default" });

    test("a role added on the form appears on the list with its level", async ({ page }) => {
        const name = uniqueName();
        await page.goto(`${rolesPath}/create`);
        await expect(page.getByPlaceholder("Enter role name (e.g., 'Sales Manager')")).toBeVisible();

        await page.getByPlaceholder("Enter role name (e.g., 'Sales Manager')").fill(name);
        await page.getByPlaceholder("Enter role description").fill("E2E made on the form");
        await page.getByRole("button", { name: "Create Role" }).click();

        // The level is on the form and required by the server; leaving it out of what the form
        // sent made every attempt fail, with nothing on screen to say which field was at fault.
        await expect(page.locator(".ant-notification-notice").filter({ hasText: "Role Created" })).toBeVisible();

        await openRoles(page);
        await search(page, name);
        await expect(rowWith(page, name)).toHaveCount(1);

        const created = roleNamed((await pageProps(page)).roles.data, name);
        made.push(created.id);
        expect(created.level, "the level the form was showing").toBe(5);
    });

    test("a name already taken is refused and says so", async ({ page, serverAs }) => {
        const api = await serverAs("super");
        await page.goto(`${rolesPath}/create`);

        await page.getByPlaceholder("Enter role name (e.g., 'Sales Manager')").fill("manager");
        await page.getByRole("button", { name: "Create Role" }).click();

        const failed = page.locator(".ant-notification-notice").filter({ hasText: "Save Failed" });
        await expect(failed).toBeVisible();
        await expect(failed, "naming the field the server objected to").toContainText(/name|taken/i);
        expect((await rolesNow(api)).filter((r) => r.name === "manager"), "still just the one").toHaveLength(1);
    });
});

test.describe("Changing a role (super user)", () => {
    test.use({ account: "super" });
    test.describe.configure({ mode: "default" });

    test("a role's level, description and permissions can be changed", async ({ page, serverAs }) => {
        const api = await serverAs("super");
        const role = await makeRole(api, { level: 40, description: "E2E before" });
        const permissions = (await pageProps(await openRolesFor(page))).permissions;
        const someId = Object.values(permissions)[0][0].id;

        const res = await apiJson(api, "PUT", roleUrl(role.id), {
            name: role.name,
            level: 41,
            description: "E2E after",
            permissions: [someId],
        });

        expect(res.status).toBeLessThan(400);
        const after = roleNamed(await rolesNow(api), role.name);
        expect(after.level).toBe(41);
        expect(after.description).toBe("E2E after");
        expect(after.permissions.map((p) => p.id)).toEqual([someId]);
    });

    /** Opens the list and hands the page back, for reading its props. */
    async function openRolesFor(page) {
        await openRoles(page);
        return page;
    }

    test("a role of one's own making can be renamed", async ({ serverAs }) => {
        const api = await serverAs("super");
        const role = await makeRole(api);
        const renamed = `${role.name} renamed`;

        const res = await apiJson(api, "PUT", roleUrl(role.id), {
            name: renamed,
            level: role.level,
            description: role.description,
        });

        expect(res.status).toBeLessThan(400);
        expect(roleNamed(await rolesNow(api), renamed), "listed under its new name").toBeTruthy();
    });

    test("a role the application depends on can't be renamed", async ({ serverAs }) => {
        const api = await serverAs("super");
        const cashier = roleNamed(await rolesNow(api), "cashier");

        const res = await apiJson(api, "PUT", roleUrl(cashier.id), {
            name: "till operator",
            level: cashier.level,
            description: cashier.description,
        });

        // Policies, the role hierarchy and the PIN checks all look this role up by name, so a
        // rename would stop every one of them matching without a word of warning.
        expect(res.status, "refused rather than carried out").toBeGreaterThanOrEqual(300);
        const after = await rolesNow(api);
        expect(roleNamed(after, "cashier"), "still called cashier").toBeTruthy();
        expect(roleNamed(after, "till operator")).toBeFalsy();
    });

    test("what a role the application depends on may do can still be changed", async ({ serverAs }) => {
        const api = await serverAs("super");
        const supervisor = roleNamed(await rolesNow(api), "supervisor");
        const before = supervisor.permissions.map((p) => p.id);

        // Keeping the name must not be mistaken for renaming it: everything else about a system
        // role is still open to change.
        const res = await apiJson(api, "PUT", roleUrl(supervisor.id), {
            name: supervisor.name,
            level: supervisor.level,
            description: "E2E description change",
            permissions: before,
        });

        expect(res.status, "its own name is not a rename").toBeLessThan(400);
        const after = roleNamed(await rolesNow(api), "supervisor");
        expect(after.description).toBe("E2E description change");
        expect(after.permissions.map((p) => p.id).sort(), "left with what it had").toEqual([...before].sort());

        // This role is shared by the whole installation, so put its description back.
        await apiJson(api, "PUT", roleUrl(supervisor.id), {
            name: supervisor.name,
            level: supervisor.level,
            description: supervisor.description,
            permissions: before,
        });
        expect(roleNamed(await rolesNow(api), "supervisor").description, "as it was").toBe(supervisor.description);
    });
});

test.describe("Removing a role (super user)", () => {
    test.use({ account: "super" });
    test.describe.configure({ mode: "default" });

    test("a role nobody holds is deleted", async ({ serverAs }) => {
        const api = await serverAs("super");
        const role = await makeRole(api);

        const res = await apiJson(api, "DELETE", roleUrl(role.id));

        expect(res.status).toBe(200);
        expect(roleNamed(await rolesNow(api), role.name), "off the list").toBeFalsy();
        made.pop();
    });

    // RolePolicy::delete refuses a system role and a role somebody holds before the controller is
    // reached, so both come back as a flat refusal rather than the controller's explanation of it.
    test("a role the application depends on can't be deleted", async ({ serverAs }) => {
        const api = await serverAs("super");
        const supervisor = roleNamed(await rolesNow(api), "supervisor");

        const res = await apiJson(api, "DELETE", roleUrl(supervisor.id));

        expect(res.status).toBe(403);
        expect(roleNamed(await rolesNow(api), "supervisor"), "still there").toBeTruthy();
    });

    test("a role somebody holds can't be deleted", async ({ serverAs }) => {
        const api = await serverAs("super");
        const cashier = roleNamed(await rolesNow(api), "cashier");

        const res = await apiJson(api, "DELETE", roleUrl(cashier.id));

        expect(res.status).toBe(403);
        expect(roleNamed(await rolesNow(api), "cashier"), "still there").toBeTruthy();
    });
});

test.describe("Roles access", () => {
    for (const account of ["admin", "manager", "cashier"]) {
        test(`${account} can't reach role management`, async ({ serverAs }) => {
            const api = await serverAs(account);

            // Roles are shared by every organization, so nobody inside one may touch them: no
            // organization role is granted any roles.* permission.
            expect((await apiJson(api, "GET", rolesPath)).status, "the list").toBe(403);
            expect((await apiJson(api, "GET", `${rolesPath}/create`)).status, "the form").toBe(403);
        });
    }

    test("an admin can't add a role of their own", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await apiJson(api, "POST", rolesPath, {
            name: "e2e admin invented",
            level: 2,
            description: "should never exist",
        });

        expect(res.status).toBe(403);
        const listed = await rolesNow(await serverAs("super"));
        expect(listed.map((r) => r.name)).not.toContain("e2e admin invented");
    });

    test("an admin can't change what a role may do", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const superUser = await serverAs("super");
        const cashier = roleNamed(await rolesNow(superUser), "cashier");

        const res = await apiJson(api, "PUT", roleUrl(cashier.id), {
            name: "cashier",
            level: 1,
            description: "E2E raised themselves up",
            permissions: [],
        });

        expect(res.status).toBe(403);
        const after = roleNamed(await rolesNow(superUser), "cashier");
        expect(after.level, "its level is untouched").toBe(cashier.level);
        expect(after.permissions.length, "and it keeps its permissions").toBe(cashier.permissions.length);
    });

    test("a new role needs a name and a level within the hierarchy", async ({ serverAs }) => {
        const api = await serverAs("super");

        expect(Object.keys((await apiJson(api, "POST", rolesPath, {})).body?.errors ?? {}).sort()).toEqual([
            "level",
            "name",
        ]);
        const tooDeep = await apiJson(api, "POST", rolesPath, { name: uniqueName(), level: 100 });
        expect(Object.keys(tooDeep.body?.errors ?? {}), "99 is as deep as it goes").toContain("level");
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(rolesPath);

        await expect(page).toHaveURL(/\/login$/);
    });
});
