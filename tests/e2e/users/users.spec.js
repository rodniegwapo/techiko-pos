import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption, notice } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { USERS } from "../support/users.js";

/**
 * The users page (User Management).
 *
 * The people listed here are the organization's seeded accounts: the demo admin, managers and
 * cashiers from database/seeders/UserSeeder.php plus the suite's own cashiers and wallet managers
 * (E2EUserSeeder, E2ESalesSeeder, E2EWalletSeeder), which together come to more than one page.
 * The rest of the suite signs in as those accounts, so nothing here edits them: a test that needs
 * to change a user adds its own first and removes it again afterwards.
 *
 * Users added by a test are removed as a super user, because only a super user or a super admin
 * may delete one (see UserPolicy::delete) — the organization's own admin may not.
 *
 * Only an admin (and, for reading, a supervisor) may open the page: RolePermissionSeeder grants
 * users.index to those two roles alone, so a manager and a cashier are refused.
 */

const usersPath = "/domains/jollibee-corp/users";
const listUrl = (query = "") => `${usersPath}${query}`;
const PER_PAGE = 15;
const PASSWORD = "e2e-password";
/** Role ids from Roleseeder: 1 super admin, 2 admin, 3 manager, 4 supervisor, 5 cashier. */
const CASHIER_ROLE_ID = 5;

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
/** The columns an organization admin sees; a super user also gets Domain and Hierarchy. */
const cell = (row, name) =>
    row.locator("td").nth(["user", "roles", "status", "location", "created"].indexOf(name));

/** Users created by the test that is running; removed again in afterEach. */
const created = [];

const uniqueEmail = () => `e2e-user-${Math.random().toString(36).slice(2, 8)}@techiko.test`;

async function openUsers(page, url = listUrl()) {
    await page.goto(url);
    await expect(page.locator(".ant-table")).toBeVisible();
}

async function search(page, text) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await page.getByPlaceholder("Search users by name or email...").fill(text);
    await found;
}

/** Picks a value in the filter popover and waits for the list it asks for. */
async function filterBy(page, label, option, param, value) {
    await page.locator("button:has(.anticon-filter)").click();
    const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Filters:" });
    const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get(param) === value);
    await pickSelectOption(page, popover.locator(".ant-form-item").filter({ hasText: label }), 0, option);
    await loaded;
}

/** Adds a user through the page's own form. */
async function addUser(page, { name, email, role = "cashier", password = PASSWORD, confirmation = password }) {
    await page.getByRole("button", { name: "Add User" }).click();
    const dialog = page.getByRole("dialog").filter({ hasText: "Add New User" });
    await expect(dialog).toBeVisible();

    await dialog.getByRole("textbox", { name: /Full Name/ }).fill(name);
    await dialog.getByRole("textbox", { name: /Email Address/ }).fill(email);
    if (role) {
        await pickSelectOption(page, dialog.locator(".ant-form-item").filter({ hasText: "Role" }), 0, role);
    }
    if (password) {
        await dialog.getByLabel("Password", { exact: true }).fill(password);
        await dialog.getByLabel("Confirm Password").fill(confirmation);
    }
    await dialog.getByRole("button", { name: "Add User" }).last().click();

    return dialog;
}

/** The user with this email as the server has them, so the afterEach can remove them. */
async function remember(page, email) {
    const props = await responseProps(await page.request.get(listUrl(`?search=${encodeURIComponent(email)}`)));
    const user = props.items.data.find((u) => u.email === email);
    if (user) {
        created.push(user.id);
    }

    return user;
}

/** Every user of the organization, walked page by page over the list's own page size. */
async function allUsers(api) {
    const out = [];
    for (let page = 1; ; page++) {
        const props = await responseProps(await api.get(listUrl(`?page=${page}`)));
        out.push(...props.items.data);
        if (page >= props.items.meta.last_page) {
            return out;
        }
    }
}

/** A user of the other organization, read as a super user (nobody else may look). */
async function otherOrgUser(serverAs, email = USERS.mcCashier.email) {
    const superUser = await serverAs("super");
    const read = async () => (await responseProps(await superUser.get("/domains/mcdonalds-corp/users"))).items.data;
    const user = (await read()).find((u) => u.email === email);
    expect(user, `${email} is seeded`).toBeTruthy();

    return { user, reread: async () => (await read()).find((u) => u.id === user.id) };
}

test.afterEach(async ({ serverAs, observe }) => {
    if (! created.length) {
        return;
    }
    const superUser = await serverAs("super");
    while (created.length) {
        const id = created.pop();
        // A user left behind would sit in every later run's list, so say so rather than move on.
        const res = await apiJson(superUser, "DELETE", `/api/users/${id}`);
        if (res.status >= 400) {
            observe(`user ${id} could not be removed after the test (${res.status})`);
        }
    }
});

test.describe("Users list (admin)", () => {
    test.use({ account: "admin" });

    test("lists the organization's users 15 to a page, newest first", async ({ page }) => {
        await openUsers(page);

        const { items } = await pageProps(page);
        expect(items.meta.per_page).toBe(PER_PAGE);
        expect(items.meta.total).toBeGreaterThan(PER_PAGE);
        await expect(rows(page)).toHaveCount(PER_PAGE);
        await expect(page.locator(".ant-pagination")).toContainText(`of ${items.meta.total} items`);

        const dates = items.data.map((u) => Date.parse(u.created_at));
        expect(dates, "newest first").toEqual([...dates].sort((a, b) => b - a));
    });

    test("page 2 asks the server for its second page", async ({ page }) => {
        await openUsers(page);
        const { items } = await pageProps(page);

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        await expect(rows(page)).toHaveCount(Math.min(PER_PAGE, items.meta.total - PER_PAGE));
    });

    test("a row shows the account's name, email, role, status and when it was created", async ({ page }) => {
        await openUsers(page);

        await search(page, USERS.cashier.email);

        const row = rows(page).first();
        await expect(cell(row, "user")).toContainText(USERS.cashier.email);
        await expect(cell(row, "roles")).toContainText("cashier");
        await expect(cell(row, "status")).toContainText("Active");
        await expect(cell(row, "created")).toHaveText(/\w+ \d{1,2}, \d{4}/);
    });

    test("a row shows the store the account is assigned to", async ({ page }) => {
        await openUsers(page);

        await search(page, USERS.cashier.email);

        // cashier1 is seeded into JB-MAIN (UserSeeder), so the column must not claim there is none.
        const location = cell(rows(page).first(), "location");
        await expect(location).not.toContainText("No location assigned");

        // The column is an info button; the store itself is named in the panel it opens.
        await location.getByRole("button").click();
        const details = page.getByRole("dialog").filter({ hasText: "Location Information" });
        await expect(details).toContainText("Jollibee Main Store");
        await expect(details).toContainText("store");
    });

    test("an account with no store says so", async ({ page }) => {
        await openUsers(page);

        await search(page, USERS.admin.email);

        await expect(cell(rows(page).first(), "location")).toContainText("No location assigned");
    });

    for (const [what, term] of [
        ["name", "Manager"],
        ["email", USERS.manager.email],
    ]) {
        test(`search finds an account by ${what}`, async ({ page }) => {
            await openUsers(page);

            await search(page, term);

            await expect(rowWith(page, USERS.manager.email)).toHaveCount(1);
        });
    }

    test("the search box shows the term the list is filtered by", async ({ page }) => {
        await openUsers(page, listUrl(`?search=${encodeURIComponent(USERS.manager.email)}`));

        await expect(page.getByPlaceholder("Search users by name or email...")).toHaveValue(USERS.manager.email);
        await expect(rows(page)).toHaveCount(1);
    });

    test("the role filter shows only that role", async ({ page }) => {
        await openUsers(page);

        await filterBy(page, "Role", "manager", "role", "manager");

        await expect(rowWith(page, USERS.manager.email)).toHaveCount(1);
        await expect(rowWith(page, USERS.cashier.email)).toHaveCount(0);
        for (const roles of await cell(rows(page), "roles").allInnerTexts()) {
            expect(roles.trim()).toBe("manager");
        }
        await expect(page.locator(".ant-tag").filter({ hasText: "Role" })).toContainText("manager");
    });

    test("only this organization's accounts are listed", async ({ page }) => {
        await openUsers(page);

        const domains = new Set((await allUsers(page.request)).map((u) => u.domain));
        expect([...domains]).toEqual(["jollibee-corp"]);
    });

    test("a user's details open in a dialog", async ({ page }) => {
        await openUsers(page);
        await search(page, USERS.manager.email);

        await rows(page).first().getByRole("button", { name: "View Details" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "User Details" });
        await expect(dialog).toContainText(USERS.manager.email);
        await expect(dialog).toContainText("manager");
    });
});

test.describe("Adding and editing a user (admin)", () => {
    test.use({ account: "admin" });
    // These add accounts, whose email has to be free. One at a time, in order.
    test.describe.configure({ mode: "default" });

    test("a new user is added to the list", async ({ page }) => {
        const email = uniqueEmail();
        const name = `E2E Added ${email.slice(9, 15)}`;
        await openUsers(page);

        await addUser(page, { name, email });

        await expect(notice(page, "User Created")).toBeVisible();
        const user = await remember(page, email);
        expect(user.name).toBe(name);
        expect(user.domain, "created inside this organization").toBe("jollibee-corp");
        await search(page, email);
        await expect(cell(rows(page).first(), "roles")).toContainText("cashier");
        await expect(cell(rows(page).first(), "status")).toContainText("Active");
    });

    test("the new account can sign in with the password it was given", async ({ page, serverAs }) => {
        const email = uniqueEmail();
        await openUsers(page);
        await addUser(page, { name: "E2E Can Sign In", email });
        await expect(notice(page, "User Created")).toBeVisible();
        await remember(page, email);

        // serverAs only knows the named accounts, so post the login form the way it does.
        const fresh = await serverAs("admin");
        await fresh.get("/login");
        const xsrf = (await fresh.storageState()).cookies.find((c) => c.name === "XSRF-TOKEN");
        const res = await fresh.post("/login", {
            form: { email, password: PASSWORD },
            headers: { "X-XSRF-TOKEN": decodeURIComponent(xsrf.value), Accept: "text/html" },
            maxRedirects: 0,
        });

        expect(res.status(), "the new account is accepted at login").toBe(302);
        expect(res.headers().location).not.toMatch(/\/login$/);
    });

    test("a user needs a name", async ({ page }) => {
        await openUsers(page);

        await page.getByRole("button", { name: "Add User" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Add New User" });
        await dialog.getByRole("textbox", { name: /Email Address/ }).fill(uniqueEmail());
        await dialog.getByRole("button", { name: "Add User" }).last().click();

        await expect(dialog.locator(".ant-form-item-explain-error").first()).toBeVisible();
        await expect(dialog).toBeVisible();
    });

    test("the two passwords have to match", async ({ page }) => {
        await openUsers(page);

        await addUser(page, {
            name: "E2E Mismatched",
            email: uniqueEmail(),
            confirmation: `${PASSWORD}-other`,
        });

        const dialog = page.getByRole("dialog").filter({ hasText: "Add New User" });
        await expect(dialog.locator(".ant-form-item-explain-error")).toContainText("Passwords do not match");
        await expect(dialog).toBeVisible();
    });

    test("an email already in use is refused", async ({ page }) => {
        await openUsers(page);

        await addUser(page, { name: "E2E Duplicate Email", email: USERS.manager.email });

        await expect(notice(page, "already been taken")).toBeVisible();
    });

    test("editing a user saves it and says so", async ({ page }) => {
        const email = uniqueEmail();
        await openUsers(page);
        await addUser(page, { name: "E2E Before Edit", email });
        await expect(notice(page, "User Created")).toBeVisible();
        await remember(page, email);
        await search(page, email);

        await rows(page).first().getByRole("button", { name: "Edit User" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Edit User" });
        await dialog.getByRole("textbox", { name: /Full Name/ }).fill("E2E After Edit");
        await dialog.getByRole("button", { name: "Update User" }).last().click();

        await expect(notice(page, "User Updated")).toBeVisible();
        await expect(dialog, "the dialog closes once it is saved").toBeHidden();
        await expect(rows(page).first()).toContainText("E2E After Edit");
    });

    test("a PIN can be set for a user", async ({ page }) => {
        const email = uniqueEmail();
        await openUsers(page);
        await addUser(page, { name: "E2E Needs A Pin", email });
        await expect(notice(page, "User Created")).toBeVisible();
        await remember(page, email);
        await search(page, email);

        await rows(page).first().getByRole("button", { name: "Set PIN" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Set user PIN" });
        await dialog.getByLabel("PIN", { exact: true }).fill("9317");
        await dialog.getByLabel("Confirm PIN").fill("9317");
        await dialog.getByRole("button", { name: "Save PIN" }).click();

        await expect(notice(page, "PIN saved")).toBeVisible();
        await expect(dialog).toBeHidden();
    });

    test("a PIN has to be repeated correctly", async ({ page }) => {
        const email = uniqueEmail();
        await openUsers(page);
        await addUser(page, { name: "E2E Bad Pin Confirm", email });
        await expect(notice(page, "User Created")).toBeVisible();
        await remember(page, email);
        await search(page, email);

        await rows(page).first().getByRole("button", { name: "Set PIN" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Set user PIN" });
        await dialog.getByLabel("PIN", { exact: true }).fill("9317");
        await dialog.getByLabel("Confirm PIN").fill("1111");
        await dialog.getByRole("button", { name: "Save PIN" }).click();

        await expect(dialog.locator(".ant-form-item-explain-error").first()).toBeVisible();
        await expect(dialog).toBeVisible();
    });

    test("deactivating a user shows them as inactive, and the status filter finds them", async ({ page }) => {
        const email = uniqueEmail();
        await openUsers(page);
        await addUser(page, { name: "E2E To Deactivate", email });
        await expect(notice(page, "User Created")).toBeVisible();
        await remember(page, email);
        await search(page, email);

        await cell(rows(page).first(), "status").locator(".ant-switch").click();
        await expect(notice(page, "Status Updated")).toBeVisible();

        await openUsers(page, listUrl("?status=inactive"));
        await expect(rowWith(page, email), "listed among the inactive").toHaveCount(1);

        await openUsers(page, listUrl("?status=active"));
        await expect(rowWith(page, email), "and left out of the active").toHaveCount(0);
    });

    test("an admin can't delete a user, and isn't offered it", async ({ page }) => {
        const email = uniqueEmail();
        await openUsers(page);
        await addUser(page, { name: "E2E Not Deletable", email });
        await expect(notice(page, "User Created")).toBeVisible();
        const user = await remember(page, email);
        await search(page, email);

        // Only a super user or a super admin may delete (UserPolicy::delete), so the row must not
        // offer an action that always fails.
        await expect(rows(page).first().getByRole("button", { name: "Delete User" })).toHaveCount(0);
        expect((await apiJson(page.request, "DELETE", `${usersPath}/${user.id}`)).status).toBe(403);
    });
});

test.describe("Users access (API)", () => {
    test("a cashier can't open the users page", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        expect((await apiJson(api, "GET", usersPath)).status).toBe(403);
    });

    test("a manager can't open the users page", async ({ serverAs }) => {
        const api = await serverAs("manager");

        expect((await apiJson(api, "GET", usersPath)).status).toBe(403);
    });

    test("another organization's manager can't list Jollibee's users", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", usersPath)).status).toBe(403);
    });

    test("a user of another organization can't be changed", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { user, reread } = await otherOrgUser(serverAs);

        const res = await apiJson(api, "PUT", `${usersPath}/${user.id}`, {
            name: "E2E Hijacked",
            email: user.email,
            role_id: CASHIER_ROLE_ID,
        });

        expect(res.status).toBe(403);
        expect((await reread()).name, "their user is untouched").toBe(user.name);
    });

    test("a user of another organization can't be changed through the global API either", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { user, reread } = await otherOrgUser(serverAs);

        const res = await apiJson(api, "PUT", `/api/users/${user.id}`, {
            name: "E2E Hijacked Globally",
            email: user.email,
            role_id: CASHIER_ROLE_ID,
        });

        expect(res.status).toBe(403);
        expect((await reread()).name, "their user is untouched").toBe(user.name);
    });

    test("another organization's user can't be deactivated", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { user, reread } = await otherOrgUser(serverAs);

        const res = await apiJson(api, "PATCH", `/api/users/${user.id}/toggle-status`);

        expect(res.status).toBe(403);
        expect((await reread()).status, "their user is left as they had it").toBe(user.status);
    });

    test("another organization's user can't be given a PIN", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const { user } = await otherOrgUser(serverAs);

        const res = await apiJson(api, "PUT", `${usersPath}/${user.id}/pin`, {
            pin_code: "4242",
            pin_code_confirmation: "4242",
        });

        expect(res.status).toBe(403);
    });

    test("a user who has been given a PIN can still be deleted", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const superUser = await serverAs("super");
        const email = uniqueEmail();

        const made = await apiJson(api, "POST", usersPath, {
            name: "E2E Pinned Then Deleted",
            email,
            password: PASSWORD,
            password_confirmation: PASSWORD,
            role_id: CASHIER_ROLE_ID,
        });
        expect(made.status).toBe(201);
        const id = made.body.user.data?.id ?? made.body.user.id;
        created.push(id);

        const pin = await apiJson(api, "PUT", `${usersPath}/${id}/pin`, {
            pin_code: "5318",
            pin_code_confirmation: "5318",
        });
        expect(pin.status, "the PIN is saved").toBe(200);

        // The PIN's foreign key used to hold the account in place, and the PIN it left behind went
        // on occupying those digits for the whole organization.
        expect((await apiJson(superUser, "DELETE", `/api/users/${id}`)).status).toBe(200);
        created.pop();

        const reuse = await apiJson(api, "POST", usersPath, {
            name: "E2E Reuses The PIN",
            email: uniqueEmail(),
            password: PASSWORD,
            password_confirmation: PASSWORD,
            role_id: CASHIER_ROLE_ID,
        });
        created.push(reuse.body.user.data?.id ?? reuse.body.user.id);
        const again = await apiJson(api, "PUT", `${usersPath}/${created.at(-1)}/pin`, {
            pin_code: "5318",
            pin_code_confirmation: "5318",
        });
        expect(again.status, "and those digits are free again").toBe(200);
    });

    test("an admin can't delete their own account", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const me = (await responseProps(await api.get(listUrl(`?search=${encodeURIComponent(USERS.admin.email)}`))))
            .items.data.find((u) => u.email === USERS.admin.email);

        const res = await apiJson(api, "DELETE", `${usersPath}/${me.id}`);

        expect(res.status, "refused rather than carried out").toBeGreaterThanOrEqual(400);
        expect((await api.get(listUrl())).status(), "the account still works").toBe(200);
    });

    test("a new user needs a name, an email, a password and a role", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await apiJson(api, "POST", usersPath, {});

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors ?? {}).sort()).toEqual(["email", "name", "password", "role_id"]);
    });

    test("an admin can't hand out the super admin role", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const email = uniqueEmail();

        const res = await apiJson(api, "POST", usersPath, {
            name: "E2E Would-Be Super Admin",
            email,
            password: PASSWORD,
            password_confirmation: PASSWORD,
            role_id: 1,
        });

        expect(res.status, "refused rather than created").toBe(422);
        const listed = (await responseProps(await api.get(listUrl(`?search=${encodeURIComponent(email)}`)))).items.data;
        expect(listed, "and nothing was created").toHaveLength(0);
    });

    for (const query of ["?role=bogus", "?status=bogus", "?page=-1", "?page=abc", "?search=%25"]) {
        test(`"${query.slice(1)}" is handled without a server error`, async ({ serverAs }) => {
            const api = await serverAs("admin");

            expect((await api.get(listUrl(query))).status()).toBeLessThan(500);
        });
    }

    test("guests are sent to login", async ({ page }) => {
        await page.goto(usersPath);
        await expect(page).toHaveURL(/\/login$/);
    });
});

/**
 * Verifying a user's email. An account made on this page starts unverified and is sent no
 * verification email, so until someone verifies it here the `verified` middleware keeps it out.
 */
test.describe("Verifying a user", () => {
    /** Makes an unverified user over HTTP, in the given organization, and remembers it for cleanup. */
    async function makeUnverified(api, { path = usersPath, domain } = {}) {
        const email = uniqueEmail();
        const res = await apiJson(api, "POST", path, {
            name: "E2E Unverified",
            email,
            password: PASSWORD,
            password_confirmation: PASSWORD,
            role_id: CASHIER_ROLE_ID,
            ...(domain ? { domain } : {}),
        });
        expect(res.status, `create ${email}`).toBe(201);
        const user = res.body.user.data ?? res.body.user;
        created.push(user.id);
        expect(user.email_verified_at, "made unverified").toBeFalsy();

        return user;
    }

    /** A user as the list shows them, read fresh. */
    async function reread(api, user, path = usersPath) {
        const props = await responseProps(await api.get(`${path}?search=${encodeURIComponent(user.email)}`));
        return props.items.data.find((u) => u.id === user.id);
    }

    const verify = (api, user) => apiJson(api, "PATCH", `/api/users/${user.id}/verify-email`);

    test.describe("on the page (admin)", () => {
        test.use({ account: "admin" });

        test("a user added on the form can be verified from the list", async ({ page }) => {
            const email = uniqueEmail();
            await openUsers(page);
            await addUser(page, { name: "E2E Needs Verifying", email });
            await expect(notice(page, "User Created")).toBeVisible({ timeout: 20_000 });
            const user = await remember(page, email);
            expect(user, "the user was made").toBeTruthy();

            await search(page, email);
            const row = rowWith(page, email);
            await expect(row, "flagged as unverified").toContainText("Unverified");

            await row.getByRole("button", { name: "Verify User" }).click();
            const confirm = page.locator(".ant-modal-confirm");
            await expect(confirm).toContainText("Verify this user? They'll be able to sign in and use the app.");
            await confirm.getByRole("button", { name: "Yes, Verify" }).click();

            await expect(notice(page, "User Verified")).toContainText("E2E Needs Verifying is now verified");
            await expect(row).not.toContainText("Unverified");
            await expect(row.getByRole("button", { name: "Verify User" }), "nothing left to verify").toHaveCount(0);
            expect((await reread(page.request, user)).email_verified_at, "stored").toBeTruthy();
        });

        test("verified users are offered no verification", async ({ page }) => {
            await openUsers(page);
            await search(page, USERS.cashier.email);

            const row = rowWith(page, USERS.cashier.email);
            await expect(row).toHaveCount(1);
            await expect(row).not.toContainText("Unverified");
            await expect(row.getByRole("button", { name: "Verify User" })).toHaveCount(0);
        });
    });

    test("verifying someone twice changes nothing the second time", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const user = await makeUnverified(api);

        expect((await verify(api, user)).status).toBe(200);
        const first = (await reread(api, user)).email_verified_at;
        const again = await verify(api, user);

        expect(again.status).toBe(200);
        expect(again.body?.message).toMatch(/already verified/i);
        expect((await reread(api, user)).email_verified_at, "the first verification stands").toBe(first);
    });

    for (const account of ["manager", "cashier"]) {
        test(`a ${account} can't verify a user`, async ({ serverAs }) => {
            const admin = await serverAs("admin");
            const user = await makeUnverified(admin);

            const res = await verify(await serverAs(account), user);

            // Neither role holds users.verify-email, even where a manager may edit a cashier.
            expect(res.status).toBe(403);
            expect((await reread(admin, user)).email_verified_at, "still unverified").toBeFalsy();
        });
    }

    test("an admin can't verify another organization's user", async ({ serverAs }) => {
        const superUser = await serverAs("super");
        const user = await makeUnverified(superUser, { path: "/api/users", domain: "mcdonalds-corp" });

        const res = await verify(await serverAs("admin"), user);

        expect(res.status).toBe(403);
        expect((await reread(superUser, user, "/domains/mcdonalds-corp/users")).email_verified_at, "still unverified").toBeFalsy();
    });

    test("a super user can verify a user in any organization", async ({ serverAs }) => {
        const superUser = await serverAs("super");
        const user = await makeUnverified(superUser, { path: "/api/users", domain: "mcdonalds-corp" });

        const res = await verify(superUser, user);

        expect(res.status).toBe(200);
        expect((await reread(superUser, user, "/domains/mcdonalds-corp/users")).email_verified_at).toBeTruthy();
    });
});
