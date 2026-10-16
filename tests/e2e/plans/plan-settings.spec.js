import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps, responseProps } from "../support/inertia.js";

/**
 * A super admin changes plans on the Plans page and each organization gets the change: the Free
 * plan's limits reach e2e-plan-free (and no paid organization), and a paid plan's features and
 * credits reach the organization on it (and not the free one). Uses the per-plan organizations
 * from E2EPlanSeeder.
 *
 * Plans are shared by every test, so this file runs in its own project after all the others
 * (playwright.config.js), one test at a time, and puts every plan back as it found it.
 * Needs FEATURE_PLAN_GATING=true; skips itself otherwise.
 */

test.describe.configure({ mode: "serial" });
test.use({ account: "super" });

const base = (plan) => `/domains/e2e-plan-${plan}`;
/** Role ids from RoleSeeder: 5 is cashier. */
const CASHIER_ROLE_ID = 5;

/** What an organization gets, read the way its own pages see it. */
async function orgView(serverAs, plan) {
    const api = await serverAs(`plan-${plan}`);
    const billing = await responseProps(await api.get(`${base(plan)}/billing/servicing`));
    const history = await responseProps(
        await api.get(`${base(plan)}/sales-history?start_date=2026-01-01&end_date=2099-12-31&location_id=all`),
    );
    return {
        api,
        features: history.domainFeatures,
        historyDays: history.historyLimit?.days ?? null,
        invoices: history.items.data.map((sale) => sale.invoice_number),
        userLimit: billing.freeTier?.user_limit ?? null,
        credits: billing.aiCredits?.allowance ?? null,
    };
}

async function addCashier(api, plan) {
    const res = await apiJson(api, "POST", `${base(plan)}/users`, {
        name: `Extra ${plan} cashier`,
        email: `e2e-plan-${plan}-${Math.random().toString(36).slice(2, 8)}@techiko.test`,
        password: "e2e-password",
        password_confirmation: "e2e-password",
        role_id: CASHIER_ROLE_ID,
    });
    return { res, id: res.body?.user?.data?.id ?? res.body?.user?.id ?? null };
}

/** Number inputs of the open plan form, in the order they appear. */
const numbers = (form) => form.getByRole("spinbutton");

async function setNumber(input, value) {
    await input.click();
    await input.fill(value == null ? "" : String(value));
    await input.blur();
}

test("plan changes on the Plans page reach the organizations on each plan", async ({ page, serverAs }) => {
    await page.goto("/plans");
    const gating = (await pageProps(page)).planGating;
    test.skip(!gating, "FEATURE_PLAN_GATING is off in .env");

    const admin = await serverAs("super");
    const before = await responseProps(await admin.get("/plans"));
    const free = before.freePlan;
    const basic = before.tiers.find((tier) => tier.slug === "basic");
    expect(basic, "the Basic plan (ServiceTierSeeder)").toBeTruthy();
    // [api, url] of users and locations to remove. Users are removed by the super admin (UserPolicy::delete).
    const added = [];
    const leftovers = [];

    try {
        await test.step("edit the Free plan: 10 days of history, 3 users, 15 AI credits", async () => {
            await page.goto("/plans");
            await page.getByRole("button", { name: "Edit Free plan" }).click();
            const form = page.getByTestId("free-plan-form");
            await expect(form).toBeVisible();
            // AI credits, max users, max products, sales history (days)
            await setNumber(numbers(form).nth(0), 15);
            await setNumber(numbers(form).nth(1), 3);
            await setNumber(numbers(form).nth(3), 10);
            await page.getByRole("button", { name: "Save Free plan" }).click();
            await expect(page.getByText("Free plan saved", { exact: true })).toBeVisible();
            await expect(form).toBeHidden();

            const row = page.locator('[data-testid="plans-table"] tbody tr.ant-table-row').first();
            await expect(row).toContainText("Free");
            await expect(row).toContainText("10 days");
        });

        await test.step("the free organization gets the new limits", async () => {
            const view = await orgView(serverAs, "free");
            expect(view.historyDays).toBe(10);
            expect(view.invoices).toContain("E2EP-FREE-5D");
            expect(view.invoices).not.toContain("E2EP-FREE-60D");
            expect(view.userLimit).toBe(3);
            expect(view.credits).toBe(15);

            // It has 2 users: a third fits, a fourth doesn't.
            const third = await addCashier(view.api, "free");
            expect(third.res.status, JSON.stringify(third.res.body)?.slice(0, 200)).toBe(201);
            added.push([admin, `${base("free")}/users/${third.id}`]);
            const fourth = await addCashier(view.api, "free");
            if (fourth.id) added.push([admin, `${base("free")}/users/${fourth.id}`]);
            expect(fourth.res.status).toBe(422);
            expect(fourth.res.body.errors.plan[0]).toContain("3 users");
        });

        await test.step("paid organizations keep their own plan", async () => {
            const view = await orgView(serverAs, "basic");
            expect(view.historyDays).toBeNull();
            expect(view.invoices).toContain("E2EP-BASIC-60D");
            expect(view.credits).toBe(basic.ai_monthly_credits);
        });

        await test.step("edit Basic: add more locations and 600 AI credits", async () => {
            await page.goto("/plans");
            await page.getByRole("button", { name: `Edit ${basic.name}` }).click();
            const form = page.getByTestId("plan-form");
            await expect(form).toBeVisible();
            // Price, AI credits, max products, max users, order
            await setNumber(numbers(form).nth(1), 600);
            await form.getByRole("checkbox", { name: "More than one location" }).check();
            await page.getByRole("button", { name: "Save plan" }).click();
            await expect(page.getByText("Plan saved", { exact: true })).toBeVisible();
            await expect(form).toBeHidden();
        });

        await test.step("the Basic organization can add a location and has 600 credits", async () => {
            const view = await orgView(serverAs, "basic");
            expect(view.features.multi_location).toBe(true);
            expect(view.credits).toBe(600);

            const code = `P${Math.random().toString(36).slice(2, 8).toUpperCase()}`;
            const res = await apiJson(view.api, "POST", `${base("basic")}/inventory/locations`, {
                name: `E2E Plan Loc ${code}`,
                code,
                type: "store",
                is_active: true,
            });
            expect([200, 201, 302], JSON.stringify(res.body)?.slice(0, 200)).toContain(res.status);
            const list = await responseProps(await view.api.get(`${base("basic")}/inventory/locations?search=${code}`));
            const location = list.locations.data.find((l) => l.code === code);
            expect(location, `created location ${code}`).toBeTruthy();
            added.push([view.api, `${base("basic")}/inventory/locations/${location.id}`]);
        });

        await test.step("the free organization still has one location", async () => {
            const view = await orgView(serverAs, "free");
            expect(view.features.multi_location).toBe(false);
        });
    } finally {
        while (added.length) {
            const [api, url] = added.pop();
            const res = await apiJson(api, "DELETE", url).catch((e) => ({ status: String(e) }));
            if (![200, 204, 302].includes(res.status)) leftovers.push(`${url} (${res.status})`);
        }
        // Put both plans back as they were.
        await apiJson(admin, "PUT", "/plans/free", {
            ai_monthly_credits: free.ai_monthly_credits,
            max_users: free.max_users,
            max_products: free.max_products,
            history_days: free.history_days,
        });
        await apiJson(admin, "PUT", `/plans/${basic.id}`, {
            name: basic.name,
            amount: basic.amount,
            ai_monthly_credits: basic.ai_monthly_credits,
            features: basic.features,
            max_products: basic.max_products,
            max_users: basic.max_users,
            is_active: basic.is_active,
            shown_on_billing: basic.shown_on_billing,
            sort_order: basic.sort_order,
        });
    }

    await test.step("both plans are back as they were", async () => {
        expect(leftovers, "users and locations the test added are removed").toEqual([]);
        const after = await responseProps(await admin.get("/plans"));
        expect(after.freePlan.history_days).toBe(free.history_days);
        expect(after.freePlan.max_users).toBe(free.max_users);
        expect(after.tiers.find((tier) => tier.id === basic.id).features).toEqual(basic.features);
        expect((await orgView(serverAs, "basic")).features.multi_location).toBe(false);
    });
});
