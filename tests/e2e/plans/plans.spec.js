import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps, responseProps } from "../support/inertia.js";

/**
 * What each plan opens and holds back, with one organization per plan (E2EPlanSeeder):
 * e2e-plan-free, -basic, -standard and -premium, each with one store, its admin and two paid sales
 * (5 and 60 days ago). The free one also has a cashier, so it is at its 2-user limit.
 *
 * Expects the plans as seeded (ServiceTierSeeder): Basic = full history; Standard adds more
 * locations, advanced inventory and expenses; Premium adds Finance. Every plan has the AI assistant. Needs
 * FEATURE_PLAN_GATING=true in the app's .env; the suite skips itself otherwise.
 */

const PLANS = ["free", "basic", "standard", "premium"];
const base = (plan) => `/domains/e2e-plan-${plan}`;
/** Role ids from RoleSeeder: 5 is cashier. */
const CASHIER_ROLE_ID = 5;

/** What each plan should give, by feature key (Domain::FEATURES). Every plan has the AI assistant, limited by its credits. */
const EXPECTED = {
    free: ["ai"],
    basic: ["sales_history_unlimited", "ai"],
    standard: ["sales_history_unlimited", "multi_location", "advanced_inventory", "expenses", "ai"],
    premium: ["sales_history_unlimited", "multi_location", "advanced_inventory", "expenses", "finance", "ai"],
};

/** Paid pages and the feature each needs. */
const PAID_PAGES = [
    { path: "/inventory/movements", feature: "advanced_inventory" },
    { path: "/inventory/valuation", feature: "advanced_inventory" },
    { path: "/expenses", feature: "expenses" },
    { path: "/finance", feature: "finance" },
    { path: "/vat-report", feature: "finance" },
];

/** Users and locations a test created, removed again after it: [api, url]. */
const created = [];

test.afterEach(async () => {
    while (created.length) {
        const [api, url] = created.pop();
        await apiJson(api, "DELETE", url).catch(() => {});
    }
});

const upgradeTags = (page) => page.locator('[data-testid="menu-upgrade"]');

async function openDashboard(page, plan) {
    await page.goto(`${base(plan)}/dashboard`);
    await expect(page.locator(".ant-menu").first()).toBeVisible();
}

async function expandGroup(page, title) {
    const group = page.locator(".ant-menu-submenu-title", { hasText: title }).first();
    await group.click();
    await expect(page.locator(".ant-menu-item", { hasText: title === "Finance" ? "Expenses" : "Movements" }).first()).toBeVisible();
}

const menuItem = (page, title) => page.locator(".ant-menu-item", { hasText: title }).first();

test.describe("plans", () => {
    // Gating is a server setting, shared with every page (the login page will do).
    let gating = true;
    test.beforeAll(async ({ playwright, baseURL }) => {
        const api = await playwright.request.newContext({ baseURL });
        gating = !!(await responseProps(await api.get("/login"))).features?.plan_gating;
        await api.dispose();
    });
    test.beforeEach(async ({}, testInfo) => {
        testInfo.skip(!gating, "FEATURE_PLAN_GATING is off in .env");
    });

    for (const plan of PLANS) {
        test.describe(`${plan} plan`, () => {
            test.use({ account: `plan-${plan}` });

            test("the organization has exactly its plan's features", async ({ page }) => {
                await openDashboard(page, plan);
                const features = (await pageProps(page)).domainFeatures;
                const on = Object.keys(features).filter((key) => features[key]);
                expect(on.sort()).toEqual([...EXPECTED[plan]].sort());
            });

            test("paid pages open only when the plan includes them", async ({ page, serverAs }) => {
                const api = await serverAs(`plan-${plan}`);
                // The refusal names the cheapest plan on the billing page that has the feature, when there is one
                // (a plan can be hidden from billing on the Plans page, e.g. Premium).
                const onSale = (await responseProps(await api.get(`${base(plan)}/billing/servicing`))).tiers;
                for (const { path, feature } of PAID_PAGES) {
                    const res = await apiJson(api, "GET", `${base(plan)}${path}`);
                    if (EXPECTED[plan].includes(feature)) {
                        expect(res.status, `${path} on ${plan}`).not.toBe(403);
                        continue;
                    }
                    expect(res.status, `${path} on ${plan}`).toBe(403);
                    const cheapest = onSale
                        .filter((tier) => (tier.features ?? []).includes(feature))
                        .sort((a, b) => Number(a.amount) - Number(b.amount))[0];
                    if (cheapest) {
                        expect(res.body?.message, `${path} on ${plan}`).toContain(`comes with the ${cheapest.name} plan`);
                    } else {
                        expect(res.body?.message, `${path} on ${plan}`).not.toContain("comes with the");
                    }
                }
            });

            test("locked pages show Upgrade in the sidebar", async ({ page }) => {
                await openDashboard(page, plan);
                await expandGroup(page, "Inventory");
                await expandGroup(page, "Finance");

                const locked = (title) => menuItem(page, title).locator('[data-testid="menu-upgrade"]');
                const expectLock = async (title, feature) =>
                    EXPECTED[plan].includes(feature)
                        ? await expect(locked(title), `${title} on ${plan}`).toHaveCount(0)
                        : await expect(locked(title), `${title} on ${plan}`).toHaveCount(1);

                await expectLock("Movements", "advanced_inventory");
                await expectLock("Valuation Report", "advanced_inventory");
                await expectLock("Expenses", "expenses");
                await expectLock("What you own & owe", "finance");
                // Every plan has the AI assistant.
                await expect(locked("AI usage"), `AI usage on ${plan}`).toHaveCount(0);

                // Free pages never carry a lock.
                for (const title of ["Products", "Stock Adjustments", "Locations"]) {
                    await expect(menuItem(page, title).locator('[data-testid="menu-upgrade"]')).toHaveCount(0);
                }
                if (plan === "premium") {
                    await expect(upgradeTags(page)).toHaveCount(0);
                }
            });

            test("sales history and export follow the plan", async ({ page, serverAs }) => {
                await page.goto(`${base(plan)}/sales-history?start_date=2026-01-01&end_date=2099-12-31&location_id=all`);
                await expect(page.getByTestId("sales-history-period")).toBeVisible();
                const props = await pageProps(page);
                const invoices = props.items.data.map((sale) => sale.invoice_number);
                const tag = plan.slice(0, 1).toUpperCase() + plan.slice(1);

                const api = await serverAs(`plan-${plan}`);
                const exported = await apiJson(api, "GET", `${base(plan)}/sales-history/export`);

                if (plan === "free") {
                    expect(props.historyLimit?.days).toBe(31);
                    await expect(page.getByTestId("sales-history-limit")).toBeVisible();
                    await expect(page.getByTestId("sales-history-export")).toHaveCount(0);
                    expect(invoices).toContain(`E2EP-${tag.toUpperCase()}-5D`);
                    expect(invoices).not.toContain(`E2EP-${tag.toUpperCase()}-60D`);
                    expect(exported.status).toBe(403);
                } else {
                    expect(props.historyLimit).toBeNull();
                    await expect(page.getByTestId("sales-history-limit")).toHaveCount(0);
                    await expect(page.getByTestId("sales-history-export")).toBeVisible();
                    expect(invoices).toEqual(expect.arrayContaining([`E2EP-${tag.toUpperCase()}-5D`, `E2EP-${tag.toUpperCase()}-60D`]));
                    expect(exported.status).not.toBe(403);
                }
            });

            test("a second location needs a multi-location plan", async ({ serverAs }) => {
                const api = await serverAs(`plan-${plan}`);
                const code = `P${Math.random().toString(36).slice(2, 8).toUpperCase()}`;
                const res = await apiJson(api, "POST", `${base(plan)}/inventory/locations`, {
                    name: `E2E Plan Loc ${code}`,
                    code,
                    type: "store",
                    is_active: true,
                });

                if (EXPECTED[plan].includes("multi_location")) {
                    expect([200, 201, 302], JSON.stringify(res.body)?.slice(0, 200)).toContain(res.status);
                    const props = await responseProps(await api.get(`${base(plan)}/inventory/locations?search=${code}`));
                    const location = props.locations.data.find((l) => l.code === code);
                    expect(location, `created location ${code}`).toBeTruthy();
                    created.push([api, `${base(plan)}/inventory/locations/${location.id}`]);
                } else {
                    expect(res.status).toBe(422);
                    expect(res.body.errors.plan[0]).toContain("Upgrade to Standard");
                }
            });

            test("the billing page marks this plan as current", async ({ page }) => {
                await page.goto(`${base(plan)}/billing/servicing`);
                const subscription = (await pageProps(page)).subscription;
                expect(subscription.is_paid).toBe(plan !== "free");
                if (plan === "free") {
                    await expect(page.getByText("Current plan").first()).toBeVisible();
                    // The Free plan's limits are set on the Plans page (seeded orgs: 2 users, 1 location).
                    const users = (await pageProps(page)).freeTier?.user_limit;
                    if (users != null) {
                        await expect(page.getByText(`${users} users · 1 location`)).toBeVisible();
                    }
                } else {
                    expect(subscription.tier_name.toLowerCase()).toContain(plan);
                }

                // Billing: the current plan up top, and Upgrade only on plans above it.
                await expect(page).toHaveTitle(/Billing/);
                await expect(page.getByTestId("current-plan-name")).toContainText(plan === "free" ? "Free" : plan, { ignoreCase: true });
                await expect(page.getByTestId("plan-usage")).toContainText("Users");
                const props = await pageProps(page);
                for (const tier of props.tiers) {
                    const upgrade = page.getByTestId(`upgrade-${tier.slug}`);
                    await expect(upgrade, `${tier.slug} on ${plan}`).toHaveCount(tier.relation === "upgrade" ? 1 : 0);
                }
                if (plan === "premium") {
                    await expect(page.locator('[data-testid^="upgrade-"]')).toHaveCount(0);
                }
                // The static QR Ph image is gone.
                await expect(page.getByAltText("QR Ph merchant code")).toHaveCount(0);
            });
        });
    }

    test.describe("upgrading", () => {
        test.use({ account: "plan-free" });

        test("Upgrade opens the payment with that plan chosen", async ({ page }) => {
            await page.goto(`${base("free")}/billing/servicing`);
            const props = await pageProps(page);
            const target = props.tiers.find((tier) => tier.relation === "upgrade");
            test.skip(!target || !props.paymentsReady, "No plan to upgrade to, or online payment isn't set up");

            await page.getByTestId(`upgrade-${target.slug}`).click();
            const choose = page.getByTestId("pay-choose");
            await expect(choose).toBeVisible();
            await expect(page.locator(".ant-modal-title")).toHaveText(`Upgrade to ${target.name}`);
            await expect(page.getByTestId(`pay-tier-${target.id}`)).toHaveClass(/border-blue-500/);
            await expect(page.getByTestId("pay-total")).toContainText(Number(target.amount).toLocaleString("en-PH", { minimumFractionDigits: 2 }));
            await expect(page.getByTestId("pay-note")).toContainText("starts right away");
            // Not asking PayMongo for a real QR code here.
        });

        test("Contact us opens the chat with the team, with a message ready", async ({ page }) => {
            await page.goto(`${base("free")}/billing/servicing`);
            await page.getByTestId("contact-team").click();
            const drawer = page.locator(".ant-drawer-content");
            await expect(drawer).toBeVisible();
            await expect(drawer.locator("textarea")).toHaveValue("I have a question about my plan (Free).");
        });
    });

    test.describe("free plan clicks", () => {
        test.use({ account: "plan-free" });

        test("a locked menu item opens the plans", async ({ page }) => {
            await openDashboard(page, "free");
            await expandGroup(page, "Inventory");
            await menuItem(page, "Movements").click();
            await expect(page).toHaveURL(new RegExp(`${base("free")}/billing/servicing`));
            await expect(page.getByRole("heading", { name: "Plans & pricing" })).toBeVisible();
        });

        test("typing a locked address sends the admin to the plans", async ({ page }) => {
            await page.goto(`${base("free")}/inventory/movements`);
            await expect(page).toHaveURL(new RegExp(`${base("free")}/billing/servicing`));
        });
    });

    test.describe("user limits", () => {
        const addCashier = async (api, plan) => {
            const email = `e2e-plan-${plan}-${Math.random().toString(36).slice(2, 8)}@techiko.test`;
            const res = await apiJson(api, "POST", `${base(plan)}/users`, {
                name: `Extra ${plan} cashier`,
                email,
                password: "e2e-password",
                password_confirmation: "e2e-password",
                role_id: CASHIER_ROLE_ID,
            });
            const id = res.body?.user?.data?.id ?? res.body?.user?.id;
            if (id) created.push([api, `${base(plan)}/users/${id}`]);
            return res;
        };

        test("the free plan stops at its user limit", async ({ serverAs }) => {
            const api = await serverAs("plan-free");
            // Set on the Plans page (2 unless a super admin changed it); the seeded org has 2 users.
            const limit = (await responseProps(await api.get(`${base("free")}/billing/servicing`))).freeTier?.user_limit;
            test.skip(limit == null, "The Free plan has no user limit");
            for (let n = 3; n <= limit; n++) {
                expect((await addCashier(api, "free")).status, `user ${n}`).toBe(201);
            }
            const res = await addCashier(api, "free");
            expect(res.status).toBe(422);
            expect(res.body.errors.plan[0]).toContain(`${limit} users`);
        });

        test("basic allows up to 5 users", async ({ serverAs }) => {
            const api = await serverAs("plan-basic");
            // The admin is user 1; four more fit, the sixth doesn't.
            for (let n = 2; n <= 5; n++) {
                const res = await addCashier(api, "basic");
                expect(res.status, `user ${n}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(201);
            }
            const sixth = await addCashier(api, "basic");
            expect(sixth.status).toBe(422);
            expect(sixth.body.errors.plan[0]).toContain("5 users");
        });

        test("standard has no user limit", async ({ serverAs }) => {
            const api = await serverAs("plan-standard");
            for (let n = 2; n <= 7; n++) {
                const res = await addCashier(api, "standard");
                expect(res.status, `user ${n}`).toBe(201);
            }
        });
    });
});

/** The super admins' Plans page: the Free plan first, then the plans, with search and filters. */
test.describe("plans page", () => {
    test.use({ account: "super" });

    const rowNames = (page) => page.locator('[data-testid="plans-table"] tbody tr.ant-table-row td:first-child .font-medium');

    test("lists the Free plan first and filters the plans", async ({ page }) => {
        await page.goto("/plans");
        await expect(rowNames(page).first()).toHaveText("Free");
        await expect(page.getByTestId("free-plan-row")).toBeVisible();

        await page.getByPlaceholder("Search plans by name").fill("prem");
        await expect(rowNames(page)).toHaveCount(1);
        await expect(rowNames(page).first()).toContainText("Premium");

        // Free is never off, so the Off filter leaves it out.
        await page.goto("/plans?status=off");
        await expect(page.getByTestId("free-plan-row")).toHaveCount(0);
        const rows = await rowNames(page).count();
        await expect(page.locator('[data-testid="plans-table"] .ant-tag', { hasText: /^Off$/ })).toHaveCount(rows);
    });

    test("the Free plan opens its own form", async ({ page }) => {
        await page.goto("/plans");
        await page.getByRole("button", { name: "Edit Free plan" }).click();
        const form = page.getByTestId("free-plan-form");
        await expect(form).toBeVisible();
        await expect(form.getByPlaceholder("Unlimited").last()).toHaveValue(/\d+/);
        await expect(form.getByText("Includes", { exact: true })).toHaveCount(0);
    });
});
