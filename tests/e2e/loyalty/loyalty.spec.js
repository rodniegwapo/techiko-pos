import { test, expect, apiJson } from "../support/fixtures.js";
import { notice } from "../support/antd.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The loyalty program page (Loyalty Program), whose four tabs are Program Rules, Customer
 * Management, Tier Management and Analytics.
 *
 * Members come from database/seeders/E2ELoyaltySeeder.php: four with fixed points, tiers and
 * lifetime spending for the list, and one per worker for the points adjustments, since those change
 * the member they act on. Tier tests create their own tier for this organization and drop it again.
 *
 * Note: the tiers seeded by LoyaltyTierSeeder have no organization, so the Tier Management tab —
 * which lists this organization's tiers — starts empty even though customers are on those tiers.
 */

const fixture = () => fixtureIds().loyalty;
const members = () => fixture().members;
const loyaltyPath = "/domains/jollibee-corp/loyalty";

/** This worker's own member, so parallel adjustments don't fight over one customer. */
const adjustable = (parallelIndex) => fixture().adjustable[parallelIndex % fixture().adjustable.length];

const peso = (amount) => `₱${Number(amount).toLocaleString("en-US", { minimumFractionDigits: 2 })}`;

const panel = (page) => page.locator(".ant-tabs-tabpane-active");
const rows = (page) => panel(page).locator(".ant-table-tbody tr.ant-table-row");
const rowWith = (page, text) => rows(page).filter({ hasText: text });
const card = (page, label) => page.locator("div.bg-white.rounded-2xl.p-6").filter({ hasText: label });

async function openLoyalty(page, tab = null) {
    await page.goto(loyaltyPath);
    await expect(page.locator(".ant-tabs")).toBeVisible();
    if (tab) {
        await openTab(page, tab);
    }
}

async function openTab(page, name) {
    await page.locator(".ant-tabs-tab", { hasText: name }).click();
    await expect(page.locator(".ant-tabs-tab-active")).toContainText(name);
}

/** The loyalty figures the page itself is showing, read from the endpoint behind it. */
async function statsOf(page) {
    const res = await apiJson(page.request, "GET", `${loyaltyPath}/stats`);
    expect(res.status, "loyalty stats").toBe(200);
    return res.body;
}

async function searchCustomers(page, text) {
    // Wait for the tab's own first load, then for the search itself, so neither can overtake the other.
    await expect(rows(page).first()).toBeVisible();
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === text);
    await panel(page).getByPlaceholder("Search customers by name, email, or phone...").fill(text);
    await found;
    await expect(rows(page)).toHaveCount(1);
}

/** Tier names are lowercase letters only, as the tier form requires — so no digits, not even in "e2e". */
const tierName = () => `etier${Math.random().toString(36).replace(/[^a-z]/g, "").padEnd(5, "x").slice(0, 5)}`;

/** The points the first listed member currently has. */
async function pointsShown(page) {
    const text = await rows(page).first().locator("td").nth(2).innerText();
    return Number(text.split("\n")[0].replace(/,/g, ""));
}

/** Fills in the points dialog for the first listed member and saves it. */
async function adjustPoints(page, { kind, amount, reason }) {
    await rows(page).first().getByRole("button", { name: "Adjust Points" }).click();
    const dialog = page.getByRole("dialog").filter({ hasText: "Adjust Customer Points" });
    await expect(dialog).toBeVisible();

    const label = kind === "deduct" ? "Deduct Points" : "Add Points";
    await dialog.locator(".ant-radio-button-wrapper", { hasText: label }).click();
    await dialog.locator(".ant-input-number-input").first().fill(String(amount));
    await dialog.locator("textarea").fill(reason);
    await dialog.getByRole("button", { name: "OK" }).click();

    return dialog;
}

/** A tier belonging to this organization, created the way the tier form does it. */
async function createTier(page, attrs = {}) {
    const name = tierName();
    const tier = {
        name,
        display_name: `E2E Tier ${name}`,
        multiplier: 1.5,
        spending_threshold: 12500,
        color: "#123456",
        description: "Playwright tier",
        sort_order: 9,
        is_active: true,
        ...attrs,
    };
    const res = await apiJson(page.request, "POST", `${loyaltyPath}/tiers`, tier);

    expect(res.status, `create tier ${name}`).toBe(201);
    created.push(res.body.data.id);

    return { ...tier, id: res.body.data.id };
}

/** Tiers this test created; dropped again in afterEach. */
const created = [];

test.afterEach(async ({ page }) => {
    while (created.length) {
        await apiJson(page.request, "DELETE", `${loyaltyPath}/tiers/${created.pop()}`).catch(() => {});
    }
});

test.describe("Loyalty program page (admin)", () => {
    test.use({ account: "admin" });

    test("opens on the program rules with the four tabs", async ({ page }) => {
        await openLoyalty(page);

        await expect(page).toHaveTitle(/Loyalty Program/);
        await expect(page.getByText("Loyalty Program", { exact: true }).first()).toBeVisible();
        await expect(page.locator(".ant-tabs-tab")).toHaveText([
            "Program Rules",
            "Customer Management",
            "Tier Management",
            "Analytics",
        ]);
        await expect(page.locator(".ant-tabs-tab-active")).toContainText("Program Rules");
    });

    test("the summary cards show the organization's own loyalty figures", async ({ page }) => {
        await openLoyalty(page);
        const stats = await statsOf(page);

        await expect(card(page, "Total Members")).toContainText(String(stats.total_customers));
        await expect(card(page, "Active Points")).toContainText(stats.total_points.toLocaleString("en-US"));
        await expect(card(page, "Total Spending")).toContainText(peso(stats.loyalty_revenue));
        await expect(card(page, "Avg. Transaction")).toContainText(peso(stats.avg_transaction));
    });

    test("the cards report figures, not invented trends", async ({ page }) => {
        await openLoyalty(page);

        await expect(card(page, "Total Members")).not.toContainText("this month");
        await expect(page.getByText("this month")).toHaveCount(0);
    });

    test("the program rules explain how points are earned and redeemed", async ({ page }) => {
        await openLoyalty(page);

        const rules = panel(page);
        await expect(rules).toContainText("1 point per ₱10 spent");
        await expect(rules).toContainText("Floor(Amount ÷ 10) × Tier Multiplier");
        await expect(rules).toContainText("100 points");
        await expect(rules).toContainText("1 point = ₱0.10");
        await expect(rules).toContainText("50% of total");
        for (const tier of ["Bronze", "Silver", "Gold", "Platinum"]) {
            await expect(rules.getByText(tier, { exact: true })).toBeVisible();
        }
    });
});

test.describe("Loyalty customers (admin)", () => {
    test.use({ account: "admin" });

    test("lists members with their tier, points and lifetime spending", async ({ page }) => {
        const { silver } = members();
        await openLoyalty(page, "Customer Management");

        await searchCustomers(page, silver.name);

        const row = rows(page).first();
        await expect(row).toContainText(silver.name);
        await expect(row).toContainText(silver.email);
        await expect(row.locator(".ant-tag")).toContainText("Silver");
        await expect(row).toContainText(silver.points.toLocaleString("en-US"));
        await expect(row, "spending is formatted").toContainText(peso(silver.spent));
    });

    test("spending with a fraction keeps its centavos", async ({ page }) => {
        const { bronze } = members();
        await openLoyalty(page, "Customer Management");

        await searchCustomers(page, bronze.name);

        await expect(rows(page).first()).toContainText(peso(bronze.spent));
    });

    for (const [what, value] of [
        ["name", () => members().gold.name],
        ["email", () => members().gold.email],
    ]) {
        test(`search finds a member by ${what}`, async ({ page }) => {
            await openLoyalty(page, "Customer Management");

            await searchCustomers(page, value());

            await expect(rows(page).first()).toContainText(members().gold.name);
        });
    }

    test("the tier filter shows only that tier's members", async ({ page }) => {
        await openLoyalty(page, "Customer Management");

        const filtered = page.waitForResponse((r) => new URL(r.url()).searchParams.get("tier") === "gold");
        await panel(page).locator(".ant-radio-button-wrapper", { hasText: "Gold" }).click();
        await filtered;

        await expect(rowWith(page, members().gold.name)).toHaveCount(1);
        await expect(rowWith(page, members().silver.name)).toHaveCount(0);
        for (const tier of await rows(page).locator(".ant-tag").allInnerTexts()) {
            expect(tier.trim()).toBe("Gold");
        }
    });

    test("a member's details show their points, spending and tier", async ({ page }) => {
        const { gold } = members();
        await openLoyalty(page, "Customer Management");
        await searchCustomers(page, gold.name);

        await rows(page).first().getByRole("button", { name: "View Details" }).click();

        const dialog = page.getByRole("dialog").filter({ hasText: "Customer Details" });
        await expect(dialog).toContainText(gold.name);
        await expect(dialog).toContainText(gold.email);
        await expect(dialog).toContainText(gold.points.toLocaleString("en-US"));
        await expect(dialog).toContainText(peso(gold.spent));
        await expect(dialog).toContainText("Gold");
    });
});

test.describe("Adjusting a member's points (admin)", () => {
    test.use({ account: "admin" });

    test("adding points raises the balance", async ({ page }, testInfo) => {
        const member = adjustable(testInfo.parallelIndex);
        await openLoyalty(page, "Customer Management");
        await searchCustomers(page, member.name);
        const before = await pointsShown(page);

        await adjustPoints(page, { kind: "add", amount: 25, reason: "E2E added" });

        await expect(notice(page, "Points Adjusted")).toBeVisible();
        await expect.poll(() => pointsShown(page)).toBe(before + 25);

        // Put the member back, so the next test starts from the same balance.
        await adjustPoints(page, { kind: "deduct", amount: 25, reason: "E2E restored" });
        await expect.poll(() => pointsShown(page)).toBe(before);
    });

    test("deducting points lowers the balance", async ({ page }, testInfo) => {
        const member = adjustable(testInfo.parallelIndex);
        await openLoyalty(page, "Customer Management");
        await searchCustomers(page, member.name);
        const before = await pointsShown(page);

        await adjustPoints(page, { kind: "deduct", amount: 40, reason: "E2E deducted" });

        await expect(notice(page, "Points Adjusted")).toBeVisible();
        await expect.poll(() => pointsShown(page)).toBe(before - 40);

        await adjustPoints(page, { kind: "add", amount: 40, reason: "E2E restored" });
        await expect.poll(() => pointsShown(page)).toBe(before);
    });

    test("the dialog opens blank for the next member, not on the last entry", async ({ page }, testInfo) => {
        const member = adjustable(testInfo.parallelIndex);
        await openLoyalty(page, "Customer Management");
        await searchCustomers(page, member.name);

        await adjustPoints(page, { kind: "deduct", amount: 5, reason: "E2E first" });
        await expect(notice(page, "Points Adjusted")).toBeVisible();

        await rows(page).first().getByRole("button", { name: "Adjust Points" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Adjust Customer Points" });
        await expect(dialog.locator(".ant-input-number-input").first()).toHaveValue("");
        await expect(dialog.locator("textarea")).toHaveValue("");
        await expect(dialog.locator(".ant-radio-button-wrapper-checked")).toContainText("Add Points");

        await dialog.getByRole("button", { name: "Cancel" }).click();
        await adjustPoints(page, { kind: "add", amount: 5, reason: "E2E restored" });
    });

    test("an adjustment without a reason isn't saved", async ({ page }, testInfo) => {
        const member = adjustable(testInfo.parallelIndex);
        await openLoyalty(page, "Customer Management");
        await searchCustomers(page, member.name);

        await rows(page).first().getByRole("button", { name: "Adjust Points" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Adjust Customer Points" });
        await dialog.locator(".ant-input-number-input").first().fill("10");
        await dialog.getByRole("button", { name: "OK" }).click();

        await expect(dialog.locator(".ant-form-item-explain-error").first()).toBeVisible();
        await expect(dialog, "the dialog stays open until it is filled in").toBeVisible();
    });
});

test.describe("Loyalty tiers (admin)", () => {
    test.use({ account: "admin" });
    // These create tiers, whose names have to be unique. One at a time, in order.
    test.describe.configure({ mode: "default" });

    test("a tier of this organization is listed with its multiplier and threshold", async ({ page }) => {
        const tier = await createTier(page);
        await openLoyalty(page, "Tier Management");

        const row = rowWith(page, tier.display_name);
        await expect(row).toContainText("1.50x");
        await expect(row).toContainText(peso(tier.spending_threshold));
        await expect(row).toContainText(tier.color);
    });

    test("a new tier can be added from the form", async ({ page }) => {
        const name = tierName();
        await openLoyalty(page, "Tier Management");

        await panel(page).getByRole("button", { name: "Add New Tier" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Add New Tier" });
        await expect(dialog).toBeVisible();
        const tierNameField = dialog.getByRole("textbox", { name: /Tier Name/ });
        const displayNameField = dialog.getByRole("textbox", { name: /Display Name/ });
        await tierNameField.fill(name);
        await displayNameField.fill(`E2E Form ${name}`);
        await expect(tierNameField).toHaveValue(name);
        await dialog.getByRole("button", { name: "OK" }).click();

        await expect(notice(page, "Tier Created")).toBeVisible({ timeout: 10_000 });
        const res = await apiJson(page.request, "GET", `${loyaltyPath}/tiers?search=${name}`);
        expect(res.body.data, "the tier the form created").toHaveLength(1);
        created.push(res.body.data[0].id);
    });

    test("a tier can be switched off and on again", async ({ page }) => {
        const tier = await createTier(page);
        await openLoyalty(page, "Tier Management");
        const row = rowWith(page, tier.display_name);

        await row.locator(".ant-switch").click();

        await expect(notice(page, "Tier Updated")).toBeVisible();
        await expect(row.locator(".ant-switch")).not.toHaveClass(/ant-switch-checked/);

        await row.locator(".ant-switch").click();
        await expect(row.locator(".ant-switch")).toHaveClass(/ant-switch-checked/);
    });

    test("the status filter separates active tiers from switched-off ones", async ({ page }) => {
        const active = await createTier(page);
        const inactive = await createTier(page, { is_active: false, sort_order: 10 });
        await openLoyalty(page, "Tier Management");

        await panel(page).locator(".ant-radio-button-wrapper").filter({ hasText: /^Inactive$/ }).click();
        await expect(rowWith(page, inactive.display_name)).toHaveCount(1);
        await expect(rowWith(page, active.display_name)).toHaveCount(0);

        await panel(page).locator(".ant-radio-button-wrapper").filter({ hasText: /^Active$/ }).click();
        await expect(rowWith(page, active.display_name)).toHaveCount(1);
        await expect(rowWith(page, inactive.display_name)).toHaveCount(0);
    });

    test("search finds a tier by name", async ({ page }) => {
        const tier = await createTier(page);
        await createTier(page, { sort_order: 11 });
        await openLoyalty(page, "Tier Management");

        await panel(page).getByPlaceholder("Search tiers by name...").fill(tier.name);

        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText(tier.display_name);
    });

    test("deleting a tier takes it off the list", async ({ page }) => {
        const tier = await createTier(page);
        await openLoyalty(page, "Tier Management");

        await rowWith(page, tier.display_name).getByRole("button", { name: "Delete Tier" }).click();
        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText(tier.display_name);
        await confirm.getByRole("button", { name: "Yes, Delete" }).click();

        await expect(notice(page, "Tier Deleted")).toBeVisible();
        await expect(rowWith(page, tier.display_name)).toHaveCount(0);
    });
});

test.describe("Loyalty analytics (admin)", () => {
    test.use({ account: "admin" });

    test("the tier distribution counts this organization's members", async ({ page }) => {
        await openLoyalty(page, "Analytics");
        const res = await apiJson(page.request, "GET", `${loyaltyPath}/analytics`);
        const bronze = res.body.tier_distribution.find((t) => t.tier === "bronze");

        const distribution = panel(page).locator("div.rounded-lg.border").filter({ hasText: "Tier Distribution" });
        await expect(distribution).toContainText("bronze");
        await expect(distribution).toContainText(String(bronze.count));
        await expect(distribution).toContainText(`${bronze.percentage}%`);
    });

    test("the points overview shows what was issued, redeemed and is still active", async ({ page }) => {
        await openLoyalty(page, "Analytics");
        const res = await apiJson(page.request, "GET", `${loyaltyPath}/analytics`);
        const stats = res.body.stats;

        const overview = panel(page).locator("div.rounded-lg.border").filter({ hasText: "Points Overview" });
        expect(stats.total_points_issued, "the organization has points issued").toBeGreaterThan(0);
        await expect(overview).toContainText(stats.total_points_issued.toLocaleString("en-US"));
        await expect(overview).toContainText(stats.active_points.toLocaleString("en-US"));
    });

    test("revenue impact compares member and non-member sales", async ({ page }) => {
        await openLoyalty(page, "Analytics");
        const res = await apiJson(page.request, "GET", `${loyaltyPath}/analytics`);
        const stats = res.body.stats;

        const revenue = panel(page).locator("div.rounded-lg.border").filter({ hasText: "Revenue Impact" });
        await expect(revenue).toContainText("Loyalty Member Sales");
        await expect(revenue).toContainText(stats.loyalty_member_sales.toLocaleString("en-US"));
        await expect(revenue).toContainText("Member Contribution");
    });
});

test.describe("Loyalty as a cashier", () => {
    test.use({ account: "cashier" });

    test("can look at members and adjust their points", async ({ page }) => {
        await openLoyalty(page, "Customer Management");

        await expect(rows(page).first()).toBeVisible();
        await expect(rows(page).first().getByRole("button", { name: "Adjust Points" })).toBeVisible();
    });

    test("can't add, edit or delete tiers", async ({ page }) => {
        await openLoyalty(page, "Tier Management");

        await expect(panel(page).getByRole("button", { name: "Add New Tier" })).toHaveCount(0);
        await expect(panel(page).getByRole("button", { name: "Edit Tier" })).toHaveCount(0);
        await expect(panel(page).getByRole("button", { name: "Delete Tier" })).toHaveCount(0);
    });
});

test.describe("Loyalty access (API)", () => {
    test("the figures only count this organization", async ({ serverAs }) => {
        const jollibee = await serverAs("admin");
        const stats = (await apiJson(jollibee, "GET", `${loyaltyPath}/stats`)).body;
        const customers = (await apiJson(jollibee, "GET", `${loyaltyPath}/customers?per_page=100`)).body;

        expect(stats.total_customers).toBe(customers.pagination.total);
        const mcCustomer = customers.data.find((c) => c.id === fixture().otherOrgCustomerId);
        expect(mcCustomer, "no member of another organization").toBeUndefined();
    });

    test("a member of another organization can't have their points changed", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await apiJson(api, "POST", `${loyaltyPath}/customers/${fixture().otherOrgCustomerId}/adjust-points`, {
            type: "add",
            amount: 10,
            reason: "E2E cross-organization probe",
        });

        expect(res.status).toBe(403);
    });

    test("an adjustment has to say how much and of what kind", async ({ serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const member = adjustable(testInfo.parallelIndex);

        const res = await apiJson(api, "POST", `${loyaltyPath}/customers/${member.id}/adjust-points`, {
            type: "multiply",
            amount: -5,
        });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors ?? {}).sort()).toEqual(["amount", "type"]);
    });

    test("another organization's manager can't read Jollibee's loyalty figures", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", `${loyaltyPath}/stats`)).status, "stats").toBe(403);
        expect((await apiJson(api, "GET", `${loyaltyPath}/customers`)).status, "customers").toBe(403);
        expect((await apiJson(api, "GET", `${loyaltyPath}/analytics`)).status, "analytics").toBe(403);
    });

    test("a cashier can't create a tier", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        const res = await apiJson(api, "POST", `${loyaltyPath}/tiers`, {
            name: "e2e-cashier-tier",
            display_name: "E2E Cashier Tier",
            multiplier: 1,
            spending_threshold: 0,
            color: "#000000",
            sort_order: 20,
        });

        expect(res.status).toBe(403);
    });

    test("a tier needs a name, a multiplier and a threshold", async ({ serverAs }) => {
        const api = await serverAs("admin");

        const res = await apiJson(api, "POST", `${loyaltyPath}/tiers`, { display_name: "E2E Incomplete" });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors ?? {}).sort()).toEqual([
            "color",
            "multiplier",
            "name",
            "sort_order",
            "spending_threshold",
        ]);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(loyaltyPath);
        await expect(page).toHaveURL(/\/login$/);
    });
});
