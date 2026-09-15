import { test, expect } from "./support/fixtures.js";
import { USERS, OTHER_DOMAIN } from "./support/users.js";
import { pageComponent, pageProps, postJson, responseProps } from "./support/inertia.js";

const DENIED_MESSAGE = "You do not have permission to access this page.";
const FORBIDDEN_JSON = "You do not have permission to access this resource.";

const orgDashboard = (domain) => `/domains/${domain}/dashboard`;
const orgSalesChart = (domain) => `/domains/${domain}/dashboard/sales-chart`;

/**
 * Sidebar entries gated by a permission, with the org page they open.
 * `allowed` lists the roles that should see and open the page (per RolePermissionSeeder).
 */
const GATED_PAGES = [
    { title: "Sales", path: "sales", allowed: ["admin", "manager", "cashier"] },
    { title: "Customers", path: "customers", allowed: ["admin", "manager", "cashier"] },
    { title: "Credit Management", path: "credits", allowed: ["admin", "manager", "cashier"] },
    { title: "Loyalty Program", path: "loyalty", allowed: ["admin", "manager", "cashier"] },
    { title: "VAT report", path: "vat-report", allowed: ["admin", "manager"] },
    { title: "Settings", path: "settings", allowed: ["admin", "manager"] },
    { title: "Void Logs", path: "void-logs", allowed: ["admin", "manager"] },
    { title: "Users", path: "users", allowed: ["admin"] },
];

/** Sidebar entry by title, e.g. "Sales" (not "Offline sales"); allows a trailing tag like "Dashboard Global Admin". */
const sidebarItem = (page, title) =>
    page.getByRole("complementary").getByRole("menuitem", { name: new RegExp(`^${title}(\\s|$)`) });

const absolute = (path) => new URL(path, test.info().project.use.baseURL).href;

/**
 * Asserts the server denies `url` for an HTTP-only session (see `serverAs`):
 * it redirects back to `from` and flashes the permission error there.
 */
async function expectDenied(api, url, from) {
    const res = await api.get(url, { headers: { Referer: absolute(from) } });

    expect(res.url(), `${url} should redirect back`).toBe(absolute(from));
    expect((await responseProps(res)).flash.error, `${url} should flash the permission error`).toBe(DENIED_MESSAGE);
}

test.describe("Dashboard as a guest", () => {
    for (const url of ["/dashboard", orgDashboard("jollibee-corp")]) {
        test(`${url} redirects to login`, async ({ page }) => {
            await page.goto(url);
            await expect(page).toHaveURL(/\/login$/);
        });
    }
});

for (const role of ["admin", "manager", "cashier"]) {
    const user = USERS[role];

    test.describe(`Dashboard as ${role}`, () => {
        test.use({ account: role });

        test("opens their organization dashboard", async ({ page }) => {
            await page.goto(orgDashboard(user.domain));

            await expect(page).toHaveURL(orgDashboard(user.domain));
            expect(await pageComponent(page)).toBe("Dashboard/Index");
            await expect(page.getByText("Here's your dashboard today")).toBeVisible();

            const props = await pageProps(page);
            expect(props.auth.user.data.is_super_user).toBeFalsy();
            expect(props.auth.user.data.roles.map((r) => r.name)).toContain(role);
            expect(props.currentDomain.name_slug).toBe(user.domain);
        });

        test("loads their organization's sales chart", async ({ page }) => {
            await page.goto(orgDashboard(user.domain));

            const res = await postJson(page, orgSalesChart(user.domain), { time_range: "daily" });

            expect(res.status).toBe(200);
            expect(res.body).toHaveProperty("raw_data");
        });

        test("is blocked from another organization's dashboard", async ({ serverAs }) => {
            await expectDenied(await serverAs(role), orgDashboard(OTHER_DOMAIN), orgDashboard(user.domain));
        });

        test("is blocked from another organization's sales chart API", async ({ page }) => {
            await page.goto(orgDashboard(user.domain));

            const res = await postJson(page, orgSalesChart(OTHER_DOMAIN), { time_range: "daily" });

            expect(res.status).toBe(403);
            expect(res.body.message).toBe(FORBIDDEN_JSON);
        });

        test("sidebar shows only permitted pages and hides admin-only menus", async ({ page }) => {
            await page.goto(orgDashboard(user.domain));
            await expect(sidebarItem(page, "Dashboard")).toBeVisible();

            for (const { title, allowed } of GATED_PAGES) {
                const item = sidebarItem(page, title);
                if (allowed.includes(role)) {
                    await expect(item, `${role} should see "${title}"`).toBeVisible();
                } else {
                    await expect(item, `${role} should not see "${title}"`).toHaveCount(0);
                }
            }

            for (const title of ["Domains", "Roles", "Permissions", "Messages"]) {
                await expect(sidebarItem(page, title), `${role} should not see "${title}"`).toHaveCount(0);
            }
        });

        test("server enforces the same page permissions as the sidebar", async ({ serverAs }) => {
            const api = await serverAs(role);

            for (const { path, allowed } of GATED_PAGES) {
                const url = `/domains/${user.domain}/${path}`;

                if (allowed.includes(role)) {
                    const res = await api.get(url);
                    expect(res.status(), `${role} should open ${url}`).toBe(200);
                    expect(res.url(), `${role} should open ${url}`).toBe(absolute(url));
                } else {
                    await expectDenied(api, url, orgDashboard(user.domain));
                }
            }
        });

        test("is redirected from the global (all organizations) dashboard to their own", async ({ page }) => {
            await page.goto("/dashboard");

            await expect(page, "non-super users must not see cross-organization totals").toHaveURL(
                orgDashboard(user.domain),
            );
            expect((await pageProps(page)).currentDomain.name_slug).toBe(user.domain);
        });

        test("cannot call the global sales chart API", async ({ page }) => {
            await page.goto(orgDashboard(user.domain));

            const res = await postJson(page, "/api/dashboard/sales-chart", { time_range: "daily" });

            expect(res.status, "global chart returns sales from every organization").toBe(403);
        });
    });
}

test.describe("Dashboard location restrictions", () => {
    /**
     * Dashboard stats for a store. Compares the data rather than the shared `currentLocation`
     * prop, which Inertia computes before RoleBasedAccessControl applies the restriction.
     */
    async function dashboardStats(page, domain, locationCode) {
        await page.goto(orgDashboard(domain));
        if (!locationCode) {
            return (await pageProps(page)).stats;
        }

        const location = (await pageProps(page)).availableLocations.find((l) => l.code === locationCode);
        expect(location, `seeded ${locationCode} location`).toBeTruthy();

        await page.goto(`${orgDashboard(domain)}?location_id=${location.id}`);
        return (await pageProps(page)).stats;
    }

    test.describe("admin", () => {
        test.use({ account: "admin" });

        test("can switch between stores", async ({ page }) => {
            const main = await dashboardStats(page, USERS.admin.domain, "JB-MAIN");
            const branch = await dashboardStats(page, USERS.admin.domain, "JB-BRANCH");

            // Also proves the two stores have distinguishable data, which the restriction tests rely on.
            expect(branch).not.toEqual(main);
            expect((await pageProps(page)).currentLocation?.code).toBe("JB-BRANCH");
        });
    });

    for (const role of ["manager", "cashier"]) {
        test.describe(role, () => {
            test.use({ account: role });

            test("only sees their assigned store even when requesting another", async ({ page }) => {
                const own = await dashboardStats(page, USERS[role].domain);
                const requestedBranch = await dashboardStats(page, USERS[role].domain, "JB-BRANCH");

                expect(requestedBranch, "data must stay on the assigned store").toEqual(own);
                expect(
                    (await pageProps(page)).currentLocation?.code,
                    "the page must show the store whose data it displays",
                ).toBe(USERS[role].locationCode);
            });
        });
    }
});

test.describe("Dashboard without the dashboard permission", () => {
    test.use({ account: "noDashboard" });
    const { domain } = USERS.noDashboard;

    test("is redirected away from the organization dashboard", async ({ serverAs }) => {
        await expectDenied(await serverAs("noDashboard"), orgDashboard(domain), `/domains/${domain}/sales`);
    });

    test("is sent to Sales instead of the global dashboard", async ({ page }) => {
        await page.goto("/dashboard");

        await expect(page).toHaveURL(`/domains/${domain}/sales`);
    });

    test("gets 403 from the sales chart API", async ({ page }) => {
        await page.goto(`/domains/${domain}/sales`);

        const res = await postJson(page, orgSalesChart(domain), { time_range: "daily" });

        expect(res.status).toBe(403);
    });

    test("can still use pages they are permitted to", async ({ page }) => {
        await page.goto(`/domains/${domain}/sales`);

        await expect(page).toHaveURL(`/domains/${domain}/sales`);
        expect(await pageComponent(page)).toBe("Sales/Index");
    });

    test("does not see Dashboard in the sidebar", async ({ page }) => {
        await page.goto(`/domains/${domain}/customers`);

        // Wait for the menu to render so an absent Dashboard item means hidden, not "not loaded yet".
        await expect(sidebarItem(page, "Customers")).toBeVisible();
        await expect(sidebarItem(page, "Dashboard")).toHaveCount(0);
    });
});

test.describe("Dashboard as super user", () => {
    test.use({ account: "super" });

    test("opens the global dashboard with super-user menus", async ({ page }) => {
        await page.goto("/dashboard");

        await expect(page).toHaveURL("/dashboard");
        expect(await pageComponent(page)).toBe("Dashboard/Index");
        expect((await pageProps(page)).auth.user.data.is_super_user).toBe(true);

        for (const title of ["Dashboard", "Domains", "Roles", "Permissions"]) {
            await expect(sidebarItem(page, title), `super user should see "${title}"`).toBeVisible();
        }
    });

    test("loads the global sales chart", async ({ page }) => {
        await page.goto("/dashboard");

        const res = await postJson(page, "/api/dashboard/sales-chart", { time_range: "daily" });

        expect(res.status).toBe(200);
    });

    for (const domain of ["jollibee-corp", OTHER_DOMAIN]) {
        test(`can open the ${domain} dashboard`, async ({ page }) => {
            await page.goto(orgDashboard(domain));

            await expect(page).toHaveURL(orgDashboard(domain));
            expect(await pageComponent(page)).toBe("Dashboard/Index");
        });
    }
});
