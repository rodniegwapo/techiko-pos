import { test, expect } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";

/**
 * The sidebar's sections, its icon-only rail and the Ctrl+K page search.
 * Uses the per-plan organizations (E2EPlanSeeder): premium has every page, free has paid pages locked.
 */

const section = (page, key) => page.getByTestId(`nav-section-${key}`);
const searchInput = (page) => page.getByTestId("nav-search-input");
const searchItems = (page) => page.getByTestId("nav-search-item");

async function openSearch(page) {
    await page.keyboard.press("Control+k");
    await expect(searchInput(page)).toBeFocused();
}

test.describe("sidebar sections", () => {
    test.describe("organization admin", () => {
        test.use({ account: "plan-premium" });

        test("sees the store sections and not Techiko admin", async ({ page }) => {
            await page.goto("/domains/e2e-plan-premium/dashboard");
            const sidebar = page.getByRole("complementary");
            for (const [key, title] of [
                ["sell", "Sell"],
                ["stock", "Catalog & stock"],
                ["money", "Money"],
                ["team", "Team & settings"],
            ]) {
                await expect(sidebar.getByTestId(`nav-section-${key}`)).toHaveText(title);
            }
            await expect(section(page, "admin")).toHaveCount(0);
        });
    });

    test.describe("super admin", () => {
        test.use({ account: "super" });

        test("sees Techiko admin on global pages", async ({ page }) => {
            await page.goto("/dashboard");
            await expect(page.getByRole("complementary").getByTestId("nav-section-admin")).toHaveText("Techiko admin");
            await expect(page.locator(".ant-menu-item", { hasText: "Plans" }).first()).toBeVisible();
        });
    });
});

test.describe("icon rail", () => {
    test.use({ account: "plan-premium" });

    test("collapses to icons, stays collapsed, and opens submenus as flyouts", async ({ page }) => {
        await page.goto("/domains/e2e-plan-premium/dashboard");
        const sider = page.locator(".ant-layout-sider").first();
        await sider.locator('a[role="button"]').first().click();
        await expect(sider.locator(".ant-menu-inline-collapsed")).toBeVisible();

        await page.reload();
        await expect(sider.locator(".ant-menu-inline-collapsed")).toBeVisible();

        await sider.locator(".ant-menu-submenu-title", { hasText: "Finance" }).hover();
        const flyout = page.locator(".ant-menu-submenu-popup:visible");
        await flyout.locator(".ant-menu-item", { hasText: "Money in & out" }).click();
        await expect(page).toHaveURL(/\/domains\/e2e-plan-premium\/finance\/cash-flow/);
        await expect(sider.locator(".ant-menu-inline-collapsed")).toBeVisible();

        // Back to the full sidebar, so the setting doesn't follow this browser around.
        await sider.locator('a[role="button"]').first().click();
        await expect(sider.locator(".ant-menu-inline-collapsed")).toHaveCount(0);
    });
});

test.describe("Ctrl+K page search", () => {
    test.describe("premium organization", () => {
        test.use({ account: "plan-premium" });

        test("finds a page by its name and opens it with Enter", async ({ page }) => {
            await page.goto("/domains/e2e-plan-premium/dashboard");
            await openSearch(page);
            await searchInput(page).fill("cash flow");
            await expect(searchItems(page).first()).toContainText("Finance › Money in & out");
            await searchInput(page).press("Enter");
            await expect(page).toHaveURL(/\/domains\/e2e-plan-premium\/finance\/cash-flow/);
            await expect(page.getByTestId("nav-search")).toBeHidden();
        });

        test("finds a page by another name it goes by", async ({ page }) => {
            await page.goto("/domains/e2e-plan-premium/dashboard");
            await openSearch(page);
            await searchInput(page).fill("p&l");
            await expect(searchItems(page).first()).toContainText("Profit & loss");

            await searchInput(page).fill("stores");
            await expect(searchItems(page).first()).toContainText("Inventory › Locations");

            await searchInput(page).fill("nothing like this");
            await expect(searchItems(page)).toHaveCount(0);
            await expect(page.getByTestId("nav-search")).toContainText("No pages match");
        });

        test("the sidebar button opens it too", async ({ page }) => {
            await page.goto("/domains/e2e-plan-premium/dashboard");
            await page.getByRole("complementary").getByTestId("nav-search-open").click();
            await expect(searchInput(page)).toBeFocused();
            await page.keyboard.press("Escape");
            await expect(page.getByTestId("nav-search")).toBeHidden();
        });
    });

    test.describe("free organization", () => {
        test.use({ account: "plan-free" });

        test("a page the plan doesn't include shows Upgrade and leads to Billing", async ({ page, playwright, baseURL }) => {
            const api = await playwright.request.newContext({ baseURL });
            const gating = !!(await responseProps(await api.get("/login"))).features?.plan_gating;
            await api.dispose();
            test.skip(!gating, "FEATURE_PLAN_GATING is off in .env");

            await page.goto("/domains/e2e-plan-free/dashboard");
            await openSearch(page);
            await searchInput(page).fill("cash flow");
            const item = searchItems(page).first();
            await expect(item).toContainText("Money in & out");
            await expect(item.getByTestId("nav-search-upgrade")).toBeVisible();
            await searchInput(page).press("Enter");
            await expect(page).toHaveURL(/\/domains\/e2e-plan-free\/billing\/servicing/);
        });
    });

    test.describe("cashier", () => {
        test.use({ account: "cashier" });

        test("only lists pages the cashier can open", async ({ page }) => {
            await page.goto("/domains/jollibee-corp/sales-history");
            await openSearch(page);
            await searchInput(page).fill("sales");
            await expect(searchItems(page).first()).toBeVisible();
            for (const name of ["users", "settings", "roles"]) {
                await searchInput(page).fill(name);
                await expect(searchItems(page).filter({ hasText: new RegExp(`^\\s*${name}\\s*$`, "i") })).toHaveCount(0);
            }
        });
    });
});
