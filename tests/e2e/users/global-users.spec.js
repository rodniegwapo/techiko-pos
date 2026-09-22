import { test, expect, apiJson } from "../support/fixtures.js";
import { pickSelectOption, notice } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { USERS } from "../support/users.js";

/**
 * The global users page at /users: User Management across every organization.
 *
 * users.spec.js covers an organization's own page (/domains/jollibee-corp/users) as its admin.
 * This one is the super user's view of everybody, which adds a Domain and a Hierarchy column, a
 * Domain filter, a Domain field on the Add User form, and the delete and impersonate actions an
 * organization's admin is never offered.
 *
 * The seeded accounts are what the rest of the suite signs in as, so nothing here changes one: a
 * test that needs to edit, verify, delete or impersonate a user makes its own first, and every
 * user a test made is removed afterwards.
 */

const usersPath = "/users";
const listUrl = (query = "") => `${usersPath}${query}`;
const PER_PAGE = 15;
const PASSWORD = "e2e-password";
/** Role ids from RoleSeeder: 1 super admin, 2 admin, 3 manager, 4 supervisor, 5 cashier. */
const CASHIER_ROLE_ID = 5;
const JOLLIBEE = { name: "Jollibee Corporation", slug: "jollibee-corp" };
const MCDONALDS = { name: "McDonald's Corporation", slug: "mcdonalds-corp" };

const rows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });

/** Users the running test made; removed again in afterEach. */
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
    await pickSelectOption(page, popover.locator(`.ant-form-item:has(label:text-is("${label}"))`), 0, option);
    await loaded;
    // The popover covers the table while it is open.
    await page.keyboard.press("Escape");
}

/** The list as the server has it for a query, read fresh (an Inertia reload leaves `data-page` alone). */
async function listed(context, query = "") {
    return (await responseProps(await context.get(listUrl(query)))).items;
}

const userWithEmail = async (context, email) =>
    (await listed(context, `?search=${encodeURIComponent(email)}`)).data.find((u) => u.email === email);

/** Makes a cashier in an organization over HTTP, remembered for cleanup; verified unless asked not to be. */
async function makeUser(api, { domain = JOLLIBEE.slug, name = "E2E Global User", verified = true } = {}) {
    const email = uniqueEmail();
    const res = await apiJson(api, "POST", "/api/users", {
        name,
        email,
        password: PASSWORD,
        password_confirmation: PASSWORD,
        role_id: CASHIER_ROLE_ID,
        domain,
    });
    expect(res.status, `create ${email}`).toBe(201);
    const user = res.body.user.data ?? res.body.user;
    created.push(user.id);

    if (verified) {
        expect((await apiJson(api, "PATCH", `/api/users/${user.id}/verify-email`)).status, `verify ${email}`).toBe(200);
    }

    return user;
}

test.afterEach(async ({ serverAs, observe }) => {
    if (!created.length) {
        return;
    }
    const superUser = await serverAs("super");
    while (created.length) {
        const id = created.pop();
        const res = await apiJson(superUser, "DELETE", `/api/users/${id}`);
        // 404 means the test already deleted it; anything else left behind would sit in every later run.
        if (res.status >= 400 && res.status !== 404) {
            observe(`user ${id} could not be removed after the test (${res.status})`);
        }
    }
});

test.describe("Users across organizations (super user)", () => {
    test.use({ account: "super" });

    test("lists every organization's users 15 to a page, with their organization and level", async ({ page }) => {
        await openUsers(page);

        const { items, isGlobalView } = await pageProps(page);
        expect(isGlobalView).toBe(true);
        expect(items.meta.total, "more than one page of them").toBeGreaterThan(PER_PAGE);
        await expect(rows(page)).toHaveCount(PER_PAGE);

        const headers = page.locator(".ant-table-thead th");
        await expect(headers.filter({ hasText: "Domain" }), "which organization each belongs to").toHaveCount(1);
        await expect(headers.filter({ hasText: "Hierarchy" })).toHaveCount(1);
    });

    test("page 2 lists the next users", async ({ page }) => {
        await openUsers(page);
        const firstPageTop = await rows(page).first().innerText();

        const loaded = page.waitForResponse((r) => new URL(r.url()).searchParams.get("page") === "2");
        await page.locator(".ant-pagination-item-2").click();
        await loaded;

        await expect(rows(page).first()).toBeVisible();
        expect(await rows(page).first().innerText()).not.toBe(firstPageTop);
    });

    test("search finds a user in another organization and names it", async ({ page }) => {
        await openUsers(page);

        await search(page, USERS.mcCashier.email);

        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(MCDONALDS.slug);
    });

    test("the super user's own row shows them as a super user of no organization", async ({ page }) => {
        await openUsers(page);

        await search(page, USERS.super.email);

        const row = rowWith(page, USERS.super.email);
        await expect(row).toHaveCount(1);
        await expect(row).toContainText("Super User");
        await expect(row, "no organization").toContainText("N/A");
        // Nobody deletes or impersonates themselves.
        await expect(row.getByRole("button", { name: "Delete User" })).toHaveCount(0);
        await expect(row.getByRole("button", { name: "Impersonate User" })).toHaveCount(0);
    });

    test("the domain filter shows one organization's users only", async ({ page }) => {
        await openUsers(page);

        await filterBy(page, "Domain", MCDONALDS.name, "domain", MCDONALDS.slug);

        await expect(page.getByText(`Domain : ${MCDONALDS.name}`)).toBeVisible();
        await expect(rows(page).first()).toBeVisible();
        await expect(rowWith(page, JOLLIBEE.slug), "nobody from the other organization").toHaveCount(0);
        for (const row of await rows(page).all()) {
            await expect(row).toContainText(MCDONALDS.slug);
        }
    });

    test("the role filter works across organizations", async ({ page }) => {
        await openUsers(page);

        await filterBy(page, "Role", "cashier", "role", "cashier");

        const cashiers = await rows(page).all();
        expect(cashiers.length).toBeGreaterThan(0);
        for (const row of cashiers) {
            await expect(row).toContainText("cashier");
        }
        await expect(rowWith(page, USERS.mcCashier.email), "McDonald's cashier among them").toHaveCount(1);
    });

    test("another organization's user can be edited, impersonated and deleted", async ({ page }) => {
        await openUsers(page);

        await search(page, USERS.mcCashier.email);

        const row = rowWith(page, USERS.mcCashier.email);
        await expect(row.getByRole("button", { name: "Edit User" })).toBeVisible();
        await expect(row.getByRole("button", { name: "Impersonate User" })).toBeVisible();
        await expect(row.getByRole("button", { name: "Delete User" })).toBeVisible();
    });
});

test.describe("Managing users across organizations (super user)", () => {
    test.use({ account: "super" });

    test("a user added into another organization is listed under it, unverified", async ({ page }) => {
        const email = uniqueEmail();
        await openUsers(page);

        await page.getByRole("button", { name: "Add User" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Add New User" });
        await expect(dialog).toBeVisible();
        await dialog.getByRole("textbox", { name: /Full Name/ }).fill("E2E Added Globally");
        await dialog.getByRole("textbox", { name: /Email Address/ }).fill(email);
        await pickSelectOption(page, dialog.locator('.ant-form-item:has(label:text-is("Domain"))'), 0, MCDONALDS.name);
        await pickSelectOption(page, dialog.locator('.ant-form-item:has(label:text-is("Role"))'), 0, "cashier");
        await dialog.getByLabel("Password", { exact: true }).fill(PASSWORD);
        await dialog.getByLabel("Confirm Password").fill(PASSWORD);
        await dialog.getByRole("button", { name: "Add User" }).last().click();

        await expect(notice(page, "User Created")).toBeVisible({ timeout: 20_000 });
        const user = await userWithEmail(page.request, email);
        expect(user, "the user was made").toBeTruthy();
        created.push(user.id);
        expect(user.domain, "in the organization picked on the form").toBe(MCDONALDS.slug);

        await search(page, email);
        const row = rowWith(page, email);
        await expect(row).toContainText(MCDONALDS.slug);
        await expect(row, "and still to be verified").toContainText("Unverified");
    });

    test("a user can be verified from the list", async ({ page, serverAs }) => {
        const user = await makeUser(await serverAs("super"), { domain: MCDONALDS.slug, verified: false });
        await openUsers(page);
        await search(page, user.email);
        const row = rowWith(page, user.email);

        await row.getByRole("button", { name: "Verify User" }).click();
        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText("Verify this user?");
        await confirm.getByRole("button", { name: "Yes, Verify" }).click();

        await expect(notice(page, "User Verified")).toContainText(`${user.name} is now verified`);
        await expect(row).not.toContainText("Unverified");
        expect((await userWithEmail(page.request, user.email)).email_verified_at).toBeTruthy();
    });

    test("a user deleted from the list is gone", async ({ page, serverAs }) => {
        const user = await makeUser(await serverAs("super"));
        await openUsers(page);
        await search(page, user.email);

        await rowWith(page, user.email).getByRole("button", { name: "Delete User" }).click();
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Yes, Delete" }).click();

        await expect(notice(page, "User Deleted")).toBeVisible();
        await expect(rowWith(page, user.email)).toHaveCount(0);
        expect(await userWithEmail(page.request, user.email), "off the list").toBeFalsy();
    });

    test("impersonating a user signs in as them, and stopping brings the super user back", async ({ page, serverAs }) => {
        const user = await makeUser(await serverAs("super"), { name: "E2E Impersonated" });
        await openUsers(page);
        await search(page, user.email);

        await rowWith(page, user.email).getByRole("button", { name: "Impersonate User" }).click();
        // The till loads one thing after another (Sales/Index.vue onMounted), starting with products.
        const tillLoading = page.waitForRequest((r) => new URL(r.url()).pathname.endsWith("/sales/products"), { timeout: 30_000 });
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Yes, Impersonate" }).click();

        // Straight into their organization's till, with a banner saying whose eyes these are.
        await expect(page).toHaveURL(new RegExp(`/domains/${JOLLIBEE.slug}/sales`), { timeout: 30_000 });
        const banner = page.getByText("Impersonation Mode Active").locator("xpath=ancestor::div[contains(@class,'bg-gradient')]");
        await expect(banner).toContainText(user.email);

        // Stop while the till is still loading. Stopping moves the browser to a new session, and the
        // till's requests still in flight carry the old one: their late answers used to set that old
        // cookie back, leaving the browser signed in as the impersonated cashier again.
        await tillLoading;
        await page.getByRole("button", { name: "Stop Impersonating" }).click();

        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });
        await expect(page.getByText("Impersonation Mode Active")).toHaveCount(0);
        await openUsers(page);
        expect((await pageProps(page)).auth.user.data.email, "signed in as themselves again").toBe(USERS.super.email);
    });
});

test.describe("Global users access", () => {
    for (const account of ["manager", "cashier"]) {
        test(`a ${account} can't open the global users page`, async ({ serverAs }) => {
            const api = await serverAs(account);

            expect((await apiJson(api, "GET", usersPath)).status).toBe(403);
        });
    }

    test("an admin who opens it sees only their own organization", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const props = await responseProps(await api.get(usersPath));

        expect(props.items.data.length).toBeGreaterThan(0);
        expect(props.items.data.every((u) => u.domain === JOLLIBEE.slug), "nobody from outside Jollibee").toBe(true);
        expect((await listed(api, `?search=${encodeURIComponent(USERS.mcCashier.email)}`)).data, "not even by search").toHaveLength(0);
        expect((await listed(api, `?domain=${MCDONALDS.slug}`)).data, "nor by filtering for them").toHaveLength(0);
    });

    test("an admin isn't told which other organizations exist", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const props = await responseProps(await api.get(usersPath));

        // The Domain filter and the Add User form's Domain field were fed every organization's name.
        expect(props.domains, "no organization list").toEqual([]);
        expect(props.isGlobalView, "so no Domain filter or field either").toBe(false);
    });

    test("opening a refused page gets a refusal, not a redirect loop", async ({ serverAs }) => {
        const api = await serverAs("cashier");
        // Visit a page the cashier may see first, so "back" has somewhere to be.
        await api.get(usersPath, { maxRedirects: 0 });

        // Laravel remembers a full page load as the previous page even when it's refused, so
        // redirecting back sent the browser to this same page until it gave up.
        const res = await api.get(usersPath, { maxRedirects: 0 });

        expect(res.status()).toBe(403);
    });

    test("a refused visit from inside the app goes back to where it came from", async ({ serverAs }) => {
        const api = await serverAs("cashier");
        const from = `/domains/${JOLLIBEE.slug}/sales`;
        await api.get(from);

        // An Inertia visit isn't remembered as the previous page, so going back is safe.
        const res = await api.get(usersPath, {
            maxRedirects: 0,
            // What Inertia's router sends; with Accept */* Laravel would take it for a JSON call.
            headers: {
                "X-Inertia": "true",
                "X-Requested-With": "XMLHttpRequest",
                Accept: "text/html, application/xhtml+xml",
                Referer: `http://techiko-pos.test${from}`,
            },
        });

        expect(res.status()).toBe(302);
        expect(res.headers().location).toContain(from);
    });

    test.describe("as an admin", () => {
        test.use({ account: "admin" });

        test("an admin is offered neither impersonation nor deleting", async ({ page }) => {
            await openUsers(page);

            await search(page, USERS.cashier.email);

            const row = rowWith(page, USERS.cashier.email);
            await expect(row).toHaveCount(1);
            await expect(row.getByRole("button", { name: "Impersonate User" })).toHaveCount(0);
            await expect(row.getByRole("button", { name: "Delete User" })).toHaveCount(0);
        });
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(usersPath);

        await expect(page).toHaveURL(/\/login$/);
    });
});
