import { test, expect, apiJson } from "../support/fixtures.js";
import { notice, pickSelectOption } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";

/**
 * Permission Management at /permissions.
 *
 * Permissions are global, like the roles that hold them: one set shared by every organization,
 * which is why the page sits outside the /domains prefix and why no organization role is granted
 * any permissions.* permission — only a super user gets there (see RolePermissionSeeder).
 *
 * A permission is what UserPermissionCheckMiddleware matches a route name against, so the seeded
 * ones are load-bearing: nothing here renames, deactivates or deletes one. Tests that need a
 * permission to change make their own in the "voids" module and remove it afterwards.
 */

const permissionsPath = "/permissions";
const permissionUrl = (id, path = "") => `${permissionsPath}/${id}${path}`;

/** The list shows this many at a time: PermissionController::index paginates 15 and ignores per_page. */
const PER_PAGE = 15;

/**
 * How long to wait for the notification a save puts up. Saving from a modal is an Inertia form
 * post, so the POST, the redirect it answers with and the reload of the list all have to finish
 * before the notification appears — longer than the default wait on a loaded local server.
 */
const SAVED_MS = 20_000;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });

/** Rows this test made; removed again in afterEach. */
const made = [];
const madeRoles = [];

const token = () => Math.random().toString(36).slice(2, 8);
const uniqueName = () => `E2E Permission ${token()}`;

/**
 * Route names carry the worker that made them, so a worker can sweep up after a test that failed
 * before it could remember what it had made without touching another worker's rows. A worker runs
 * one test at a time, which is what makes a value shared across the file safe here.
 */
let routePrefix = "voids.e2e-";
const uniqueRoute = () => `${routePrefix}${token()}`;

test.beforeEach(({}, testInfo) => {
    routePrefix = `voids.e2e-w${testInfo.parallelIndex}-`;
});

async function openPermissions(page) {
    await page.goto(permissionsPath);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await page.getByPlaceholder("Search permissions by name...").fill(text);
    await found;
}

/** Picks a module in the filter popover and waits for the list it asks for. */
async function filterByModule(page, displayName, slug) {
    await page.locator("button:has(.anticon-filter)").click();
    const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
    const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("module") === slug);
    await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: "Module" }), 0, displayName);
    await loaded;
    // The popover covers the table while it is open.
    await page.keyboard.press("Escape");
}

/** The page's `items` for a query, read fresh over HTTP (an Inertia reload leaves `data-page` alone). */
async function listed(context, query = "") {
    return (await responseProps(await context.get(`${permissionsPath}${query}`))).items;
}

const permissionNamed = async (context, name) =>
    (await listed(context, `?search=${encodeURIComponent(name)}`)).data.find((p) => p.name === name);

const permissionRouted = async (context, routeName) =>
    (await listed(context, `?search=${encodeURIComponent(routeName)}`)).data.find((p) => p.route_name === routeName);

/** Makes a permission over HTTP and remembers it for cleanup. */
async function makePermission(api, { name = uniqueName(), routeName = uniqueRoute(), module = "voids", action = "manage" } = {}) {
    const res = await apiJson(api, "POST", permissionsPath, {
        name,
        route_name: routeName,
        module,
        action,
    });
    expect(res.status, `create permission ${name}`).toBeLessThan(400);

    const created = await permissionNamed(api, name);
    expect(created, `${name} is listed after creating it`).toBeTruthy();
    made.push(created.id);

    return created;
}

/** Makes a role holding these permissions, so a permission can be seen while something holds it. */
async function makeRole(api, permissionIds) {
    const name = `e2e permission holder ${token()}`;
    const res = await apiJson(api, "POST", "/roles", {
        name,
        level: 42,
        description: "E2E role holding a permission",
        permissions: permissionIds,
    });
    expect(res.status, `create role ${name}`).toBeLessThan(400);

    const role = (await responseProps(await api.get("/roles"))).roles.data.find((r) => r.name === name);
    expect(role, `${name} is listed after creating it`).toBeTruthy();
    madeRoles.push(role.id);

    return role;
}

test.afterEach(async ({ serverAs }, testInfo) => {
    const failed = testInfo.status !== testInfo.expectedStatus;
    if (!made.length && !madeRoles.length && !failed) {
        return;
    }
    const api = await serverAs("super");
    // The roles go first: a role holding one of these permissions would otherwise keep it.
    while (madeRoles.length) {
        await apiJson(api, "DELETE", `/roles/${madeRoles.pop()}`).catch(() => {});
    }
    while (made.length) {
        await apiJson(api, "DELETE", permissionUrl(made.pop())).catch(() => {});
    }

    // A test that failed part-way through may have made a permission before reaching the line
    // that remembers it, so sweep up anything this worker left behind.
    if (failed) {
        for (const stray of (await listed(api, `?search=${routePrefix}`)).data) {
            await apiJson(api, "DELETE", permissionUrl(stray.id)).catch(() => {});
        }
    }
});

test.describe("Permissions list (super user)", () => {
    test.use({ account: "super" });

    test("lists the permissions with the module, status and roles of each", async ({ page }) => {
        await openPermissions(page);

        const { items } = await pageProps(page);
        expect(items.data.length).toBe(PER_PAGE);
        await expect(rows(page)).toHaveCount(items.data.length);

        // Every permission carries the module its route name starts with, and is on by default.
        const first = items.data[0];
        const row = rowWith(page, first.name).first();
        await expect(row).toContainText(first.module?.display_name ?? "Unknown");
        await expect(row).toContainText(first.is_active ? "Active" : "Inactive");
    });

    test("shows fifteen at a time and keeps the rest on the following pages", async ({ page }) => {
        await openPermissions(page);
        const { items } = await pageProps(page);
        expect(items.meta.total, "more than one page of them").toBeGreaterThan(PER_PAGE);
        const firstPageTop = await rows(page).first().innerText();

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        await expect(rows(page)).toHaveCount(PER_PAGE);
        expect(await rows(page).first().innerText(), "the next fifteen, in name order").not.toBe(firstPageTop);
    });

    test("search narrows the list to the permissions that match", async ({ page }) => {
        await openPermissions(page);

        // The seven that make up permission management itself.
        await search(page, "permissions.");

        await expect(rows(page)).toHaveCount(7);
        await expect(rowWith(page, "Deactivate")).toHaveCount(1);
    });

    test("search also matches a permission's route name", async ({ page }) => {
        await openPermissions(page);

        // Nothing is called this; the match is on the route name the middleware checks.
        await search(page, "permissions.deactivate");

        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText("Deactivate");
    });

    test("a search with no match shows the empty table", async ({ page }) => {
        await openPermissions(page);

        await search(page, "e2e-no-such-permission-anywhere");

        await expect(rows(page)).toHaveCount(0);
        await expect(page.locator(".ant-empty")).toBeVisible();
    });

    test("the module filter narrows the list to one module's permissions", async ({ page }) => {
        await openPermissions(page);
        const inModule = (await listed(page.request, "?module=permissions")).data;
        expect(inModule.length, "and they fit on one page").toBeLessThanOrEqual(PER_PAGE);

        await filterByModule(page, "Permissions", "permissions");

        await expect(rows(page)).toHaveCount(inModule.length);
        await expect(page.getByText("Module : Permissions")).toBeVisible();
        for (const permission of inModule) {
            await expect(rowWith(page, permission.name).first()).toContainText("Permissions");
        }
    });

    test("clearing the module filter brings the rest back", async ({ page }) => {
        await openPermissions(page);
        await filterByModule(page, "Permissions", "permissions");
        await expect(rows(page)).not.toHaveCount(PER_PAGE);

        const loaded = page.waitForResponse((r) => {
            const url = new URL(r.url());
            return r.request().method() === "GET" && url.pathname === permissionsPath && !url.searchParams.has("module");
        });
        await page.locator(".ant-tag-green .anticon-close").first().click();
        await loaded;

        await expect(rows(page)).toHaveCount(PER_PAGE);
        await expect(page.getByText("Module : Permissions")).toBeHidden();
    });

    test("the page offers adding a permission", async ({ page }) => {
        await openPermissions(page);

        await expect(page.getByRole("button", { name: "Add Permission" })).toBeVisible();
    });
});

/** A labelled block on the details page, which lays its fields out by hand rather than as a form. */
const detailField = (scope, label) => scope.locator(`div:has(> label:text-is("${label}"))`);

test.describe("A permission's details (super user)", () => {
    test.use({ account: "super" });

    test("the details page describes the permission and names the roles that hold it", async ({ page }) => {
        await openPermissions(page);
        await search(page, "permissions.index");
        const permission = await permissionRouted(page.request, "permissions.index");

        await rows(page).first().getByRole("button", { name: "View Permission Details" }).click();

        await expect(page).toHaveURL(new RegExp(`${permissionUrl(permission.id)}$`));
        // Everything on this page comes out of a PermissionResource, so it all sits under `data`:
        // reading the props straight off the wrapper left the heading blank, printed the whole
        // module object where its name belongs, and repeated the name under "Guard Name".
        const card = page.locator(".ant-card").first();
        await expect(card.locator(".ant-card-head")).toContainText(permission.name);
        await expect(detailField(card, "Module")).toContainText("Permissions");
        await expect(detailField(card, "Action")).toContainText(permission.action);
        await expect(detailField(card, "Guard Name")).toContainText(permission.guard_name);

        // Only a super user may reach permission management, so only super admin holds this one.
        await expect(page.getByText("Roles Using This Permission")).toBeVisible();
        await expect(page.getByRole("heading", { name: "super admin" })).toBeVisible();
    });
});

test.describe("Adding a permission (super user)", () => {
    test.use({ account: "super" });

    test("a permission added on the form appears on the list", async ({ page }) => {
        const name = uniqueName();
        await openPermissions(page);

        await page.getByRole("button", { name: "Add Permission" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Create New Permission" });
        await expect(dialog).toBeVisible();
        await fillPermissionForm(page, dialog, { module: "voids", action: "Manage", name, routeName: uniqueRoute() });
        await dialog.getByRole("button", { name: "Create Permission" }).click();

        await expect(notice(page, "Permission Created")).toBeVisible({ timeout: SAVED_MS });
        await search(page, name);
        const row = rowWith(page, name);
        await expect(row).toHaveCount(1);
        await expect(row, "in the module it was filed under").toContainText("Void Logs");
        await expect(row, "and usable straight away").toContainText("Active");

        made.push((await permissionNamed(page.request, name)).id);
    });

    test("a route name already in use is refused and says so", async ({ page }) => {
        await openPermissions(page);

        await page.getByRole("button", { name: "Add Permission" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Create New Permission" });
        await fillPermissionForm(page, dialog, {
            module: "voids",
            action: "Manage",
            name: uniqueName(),
            // Taken by the permission that guards this very page.
            routeName: "permissions.index",
        });
        await dialog.getByRole("button", { name: "Create Permission" }).click();

        await expect(notice(page, "Create Failed")).toBeVisible({ timeout: SAVED_MS });
        await expect(dialog, "naming the field the server objected to").toContainText(/route.name.*taken/i);
    });

    test("a new permission needs a module, an action, a name and a route name", async ({ serverAs }) => {
        const api = await serverAs("super");

        const res = await apiJson(api, "POST", permissionsPath, {});

        expect(Object.keys(res.body?.errors ?? {}).sort()).toEqual(["action", "module", "name", "route_name"]);
    });

    test("a module that isn't a usable slug is refused", async ({ serverAs }) => {
        const api = await serverAs("super");

        // The module is slugged before it is looked up or created, and "---" slugs to nothing at
        // all; without this check the permission would be filed under an empty module name.
        const res = await apiJson(api, "POST", permissionsPath, {
            name: uniqueName(),
            route_name: uniqueRoute(),
            module: "---",
            action: "manage",
        });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body?.errors ?? {})).toEqual(["module"]);
    });
});

test.describe("Changing a permission (super user)", () => {
    test.use({ account: "super" });

    test("a permission's display name can be changed on the form", async ({ page, serverAs }) => {
        const api = await serverAs("super");
        const permission = await makePermission(api);
        const renamed = `${permission.name} renamed`;

        await openPermissions(page);
        await search(page, permission.name);
        await rows(page).first().getByRole("button", { name: "Edit Permission" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Edit Permission" });
        await expect(dialog).toBeVisible();
        await dialog.getByPlaceholder("e.g., Create Product, View Users, etc.").fill(renamed);
        await dialog.getByRole("button", { name: "Update Permission" }).click();

        await expect(notice(page, "Permission Updated")).toBeVisible({ timeout: SAVED_MS });
        expect(await permissionNamed(page.request, renamed), "listed under its new name").toBeTruthy();
    });

    test("a permission keeps the roles that hold it when it's changed", async ({ serverAs }) => {
        const api = await serverAs("super");
        const permission = await makePermission(api);
        await makeRole(api, [permission.id]);

        const res = await apiJson(api, "PUT", permissionUrl(permission.id), {
            name: permission.name,
            route_name: permission.route_name,
            module: "voids",
            action: "export",
        });

        expect(res.status).toBeLessThan(400);
        const after = await permissionNamed(api, permission.name);
        expect(after.action, "the action it was moved to").toBe("export");
        expect(after.roles_count, "still held by the role that had it").toBe(1);
    });

    test("a description typed on the form is not kept", async ({ page, serverAs, observe }) => {
        const api = await serverAs("super");
        const permission = await makePermission(api);

        const res = await apiJson(api, "PUT", permissionUrl(permission.id), {
            name: permission.name,
            route_name: permission.route_name,
            module: "voids",
            action: "manage",
            description: "E2E description that goes nowhere",
        });

        // The form collects a description and the controller validates it, but the permissions
        // table has no such column and neither create nor update writes one, so the list keeps
        // saying "No description".
        expect(res.status).toBeLessThan(400);
        await openPermissions(page);
        await search(page, permission.name);
        await expect(rowWith(page, permission.name)).toContainText("No description");
        observe("Permissions: the form's Description is validated and then dropped — permissions have no description column");
    });
});

test.describe("Turning a permission off and on (super user)", () => {
    test.use({ account: "super" });

    test("a permission no role holds can be deactivated and activated again", async ({ page, serverAs }) => {
        const api = await serverAs("super");
        const permission = await makePermission(api);

        await openPermissions(page);
        await search(page, permission.name);
        await expect(rowWith(page, permission.name)).toContainText("Active");

        await rows(page).first().getByRole("button", { name: "Deactivate Permission" }).click();
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Deactivate" }).click();
        await expect(notice(page, "Permission deactivated successfully")).toBeVisible();
        await expect(rowWith(page, permission.name)).toContainText("Inactive");

        await rows(page).first().getByRole("button", { name: "Activate Permission" }).click();
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Activate" }).click();
        await expect(notice(page, "Permission activated successfully")).toBeVisible();
        await expect(rowWith(page, permission.name)).toContainText("Active");
    });

    test("a permission a role holds can't be deactivated", async ({ serverAs }) => {
        const api = await serverAs("super");
        const held = await permissionRouted(api, "permissions.activate");

        const res = await apiJson(api, "POST", permissionUrl(held.id, "/deactivate"));

        // Turning it off would leave the roles holding it pointing at a permission the middleware
        // no longer offers, so it has to be taken off them first.
        expect(res.status).toBe(400);
        expect(res.body?.message).toMatch(/assigned to roles/i);
        expect((await permissionRouted(api, "permissions.activate")).is_active, "left on").toBe(true);
    });
});

test.describe("Removing a permission (super user)", () => {
    test.use({ account: "super" });

    test("a permission no role holds is deleted from the list", async ({ page, serverAs }) => {
        const api = await serverAs("super");
        const permission = await makePermission(api);

        await openPermissions(page);
        await search(page, permission.name);
        await rows(page).first().getByRole("button", { name: "Delete Permission" }).click();
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Delete" }).click();

        await expect(notice(page, "Permission deleted successfully")).toBeVisible();
        await expect(rows(page)).toHaveCount(0);
        expect(await permissionNamed(api, permission.name), "off the list").toBeFalsy();
        made.pop();
    });

    test("a super user can delete a permission a role still holds", async ({ serverAs }) => {
        const api = await serverAs("super");
        const permission = await makePermission(api);
        const role = await makeRole(api, [permission.id]);

        const res = await apiJson(api, "DELETE", permissionUrl(permission.id));

        // Only a super user gets this far, and for them a permission in use is a logged warning
        // rather than a refusal: the role is simply left without it.
        expect(res.status).toBe(200);
        expect(await permissionNamed(api, permission.name), "off the list").toBeFalsy();
        made.pop();
        const after = (await responseProps(await api.get("/roles"))).roles.data.find((r) => r.id === role.id);
        expect(after.permissions, "the role is left, without the permission").toHaveLength(0);
    });
});

test.describe("Permissions access", () => {
    for (const account of ["admin", "manager", "cashier"]) {
        test(`${account} can't reach permission management`, async ({ serverAs }) => {
            const api = await serverAs(account);

            // Permissions are shared by every organization, so nobody inside one may touch them:
            // no organization role is granted any permissions.* permission.
            expect((await apiJson(api, "GET", permissionsPath)).status, "the list").toBe(403);
        });
    }

    test("an admin can't add a permission", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const name = uniqueName();

        const res = await apiJson(api, "POST", permissionsPath, {
            name,
            route_name: uniqueRoute(),
            module: "voids",
            action: "manage",
        });

        expect(res.status).toBe(403);
        expect(await permissionNamed(await serverAs("super"), name), "never made").toBeFalsy();
    });

    test("an admin can't delete a permission", async ({ serverAs }) => {
        const superUser = await serverAs("super");
        const api = await serverAs("admin");
        const held = await permissionRouted(superUser, "permissions.index");

        const res = await apiJson(api, "DELETE", permissionUrl(held.id));

        expect(res.status).toBe(403);
        expect(await permissionRouted(superUser, "permissions.index"), "still there").toBeTruthy();
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(permissionsPath);

        await expect(page).toHaveURL(/\/login$/);
    });
});

/** The form item a label belongs to. The module field's placeholder never reaches its input. */
const field = (dialog, label) => dialog.locator(`.ant-form-item:has(label:text-is("${label}"))`);

/**
 * Fills the create/edit modal. The module field is an autocomplete whose dropdown covers the
 * fields below it, so it is dismissed before the rest is filled; picking the action rewrites the
 * route name, so that goes in last.
 */
async function fillPermissionForm(page, dialog, { module, action, name, routeName }) {
    await field(dialog, "Module").getByRole("combobox").fill(module);
    // Tab rather than Escape: Escape closes the whole modal, not just the suggestions.
    await page.keyboard.press("Tab");

    await field(dialog, "Action").locator(".ant-select").click();
    const dropdown = page.locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden)").last();
    await expect(dropdown).toBeVisible();
    await dropdown.getByText(action, { exact: true }).click();

    await field(dialog, "Display Name").getByRole("textbox").fill(name);
    await field(dialog, "Route Name").getByRole("textbox").fill(routeName);
}
