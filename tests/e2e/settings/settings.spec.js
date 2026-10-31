import { test, expect } from "../support/fixtures.js";
import { pickSelectOption } from "../support/antd.js";
import { pageProps } from "../support/inertia.js";
import { businessTypeOpen, openFeatures } from "../support/comingSoon.js";
import { BUSINESS_TYPES } from "../support/users.js";

/**
 * Settings, on its own organization (E2ESettingsSeeder: e2e-settings, one store, an admin, a manager
 * and one product; pesos, no VAT and no business type picked at the start of each run). Every card
 * saves on its own, a change survives a reload, and only the Business type card sets the type.
 * The tests change one organization's settings, so they run in order.
 * While Grocery is coming soon (FEATURE_BUSINESS_TYPES) the admin picks Retail instead.
 */
test.describe.configure({ mode: "serial" });

const settingsUrl = "/domains/e2e-settings/settings";
const card = (page, title) => page.locator(".bg-white").filter({ has: page.getByRole("heading", { name: title, exact: true }) }).first();

/** Clicks a card's Save and waits for the server to answer. */
async function save(page, title) {
    const saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === settingsUrl);
    await card(page, title).getByRole("button", { name: "Save" }).click();
    expect((await saved).status()).toBeLessThan(400);
}

test.describe("as the admin", () => {
    test.use({ account: "settings-admin" });

    test("shows the account at a glance", async ({ page }) => {
        await page.goto(settingsUrl);
        const overview = page.getByTestId("account-overview");
        await expect(overview).toContainText("E2E Settings Org");
        await expect(overview).toContainText("Active locations");
        await expect(overview.locator("dd").nth(2)).toHaveText("1");
        await expect(overview.locator("dd").nth(3)).toHaveText("2");
    });

    test("the currency is saved and used across the app", async ({ page }) => {
        await page.goto(settingsUrl);
        await pickSelectOption(page, card(page, "Currency"), 0, "US dollar");
        await save(page, "Currency");

        await page.reload();
        await expect(page.getByTestId("currency-select")).toContainText("US dollar ($)");
        expect((await pageProps(page)).currentDomain.currency_code).toBe("USD");

        // Back to pesos for the rest of the run.
        await pickSelectOption(page, card(page, "Currency"), 0, "Philippine peso");
        await save(page, "Currency");
        await page.reload();
        await expect(page.getByTestId("currency-select")).toContainText("Philippine peso");
    });

    test("VAT settings are saved, and the explanation follows the pricing mode", async ({ page }) => {
        await page.goto(settingsUrl);
        const sales = card(page, "Sales");
        await expect(sales).toContainText("VAT is added on top of the net");

        await sales.getByRole("switch").first().click();
        await sales.getByText("Inclusive (VAT in price)").click();
        await expect(sales, "the text changes before saving").toContainText("line totals are treated as VAT-inclusive");
        await sales.locator(".ant-input-number input").fill("10");
        await save(page, "Sales");

        await page.reload();
        const settings = (await pageProps(page)).salesSettings;
        expect(settings.apply_vat_automatically).toBe(true);
        expect(settings.vat_pricing_mode).toBe("inclusive");
        expect(settings.vat_rate_percent).toBe(10);
        await expect(card(page, "Sales").getByRole("switch").first()).toHaveAttribute("aria-checked", "true");
        await expect(card(page, "Sales").locator(".ant-radio-wrapper-checked")).toContainText("Inclusive");
    });

    test("stock and shift switches are saved", async ({ page }) => {
        await page.goto(settingsUrl);
        const sales = card(page, "Sales");
        const oversell = sales.locator(".ant-form-item").filter({ hasText: "Allow selling when out of stock" }).getByRole("switch");
        await oversell.click();
        await page.getByRole("switch", { name: "Hide out-of-stock products at the POS" }).click();
        await page.getByTestId("setting-new-shift-while-review").click();
        await save(page, "Sales");

        await page.reload();
        const settings = (await pageProps(page)).salesSettings;
        expect(settings.allow_overselling).toBe(true);
        expect(settings.hide_out_of_stock).toBe(true);
        expect(settings.allow_new_shift_while_review).toBe(false);
        await expect(page.getByTestId("setting-new-shift-while-review")).toHaveAttribute("aria-checked", "false");
    });

    test("saving the other cards doesn't pick a business type", async ({ page }) => {
        await page.goto(settingsUrl);
        expect((await pageProps(page)).businessTypeChosen).toBe(false);

        await page.goto("/domains/e2e-settings/products");
        await expect(page.getByTestId("business-type-banner"), "still asked what the business sells").toBeVisible();
    });

    test("the types not open yet say Coming soon and can't be picked", async ({ page }) => {
        const { businessTypes } = await openFeatures();
        const soon = BUSINESS_TYPES.filter((t) => !businessTypes.includes(t));
        test.skip(soon.length === 0, "every business type is open");

        await page.goto(settingsUrl);
        await expect(page.getByTestId("business-type-coming-soon-retail")).toHaveCount(0);
        await expect(page.getByTestId("business-type-retail")).toBeEnabled();
        for (const type of soon) {
            await expect(page.getByTestId(`business-type-coming-soon-${type}`)).toHaveText("Coming soon");
            await expect(page.getByTestId(`business-type-${type}`)).toBeDisabled();
        }
    });

    test("Retail is confirmed, saved and recorded while the other types are coming soon", async ({ page }) => {
        test.skip(await businessTypeOpen("grocery"), "Grocery is open: the next test picks it");
        await page.goto(settingsUrl);
        await page.getByTestId("business-type-retail").click();
        await page.getByTestId("save-business-type").click();

        // Never picked yet, so it's confirmed even though it already reads as retail.
        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText("Change the business type to Retail store?");
        await expect(confirm, "Tables are coming soon, so not named").not.toContainText("Tables");
        const saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === settingsUrl);
        await confirm.getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);

        await expect(page.getByTestId("business-type-retail")).toBeChecked();
        await expect(page.getByTestId("business-type-changed")).toContainText("by E2E Settings Admin on");

        await page.goto("/domains/e2e-settings/products");
        await expect(page.getByTestId("business-type-banner"), "picked, so no more banner").toHaveCount(0);
    });

    test("the business type is confirmed, saved and recorded", async ({ page }) => {
        test.skip(!(await businessTypeOpen("grocery")), "Grocery is coming soon");
        await page.goto(settingsUrl);
        await page.getByTestId("business-type-grocery").click();
        await page.getByTestId("save-business-type").click();

        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText("Change the business type to Grocery / sold by weight?");
        await expect(confirm).toContainText("Tables");
        await expect(confirm).toContainText("sold by the kilo");
        const saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === settingsUrl);
        await confirm.getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);

        await expect(page.getByTestId("business-type-grocery")).toBeChecked();
        await expect(page.getByTestId("business-type-changed")).toContainText("by E2E Settings Admin on");

        await page.goto("/domains/e2e-settings/products");
        await expect(page.getByTestId("business-type-banner"), "picked, so no more banner").toHaveCount(0);
    });
});

test.describe("as a manager", () => {
    test.use({ account: "settings-manager" });

    test("can change the sales settings but not the business type", async ({ page }) => {
        const picked = (await businessTypeOpen("grocery")) ? "grocery" : "retail";
        await page.goto(settingsUrl);
        await expect(page.getByTestId(`business-type-${picked}`), "as the admin left it").toBeChecked();
        await expect(page.getByTestId(picked === "retail" ? "business-type-grocery" : "business-type-retail")).toBeDisabled();
        await expect(page.getByTestId("show-all-menu")).toBeDisabled();
        await expect(page.getByTestId("save-business-type")).toHaveCount(0);
        await expect(page.getByTestId("business-type-read-only")).toBeVisible();

        await page.getByRole("switch", { name: "Hide out-of-stock products at the POS" }).click();
        await save(page, "Sales");
        await page.reload();
        expect((await pageProps(page)).salesSettings.hide_out_of_stock).toBe(false);
        expect((await pageProps(page)).salesSettings.business_type, "the type is kept").toBe(picked);
    });
});

test.describe("switching to a type that hides pages with records", () => {
    test.use({ account: "settings-admin" });

    const sidebarItem = (page, title) => page.getByRole("complementary").getByText(title, { exact: true });

    test("Services hides the stock pages, says the business has records there, and they can be kept", async ({ page }) => {
        test.skip(!(await businessTypeOpen("services")), "Services is coming soon");
        // e2e-settings has a product that tracks stock, so it has records in the stock pages.
        await page.goto(settingsUrl);
        await page.getByTestId("business-type-services").click();
        await expect(page.getByTestId("menu-hidden-with-records")).toContainText("Purchasing");
        await page.getByTestId("save-business-type").click();

        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm.getByTestId("confirm-records")).toContainText("You have records in");
        await expect(confirm.getByTestId("confirm-records")).toContainText("Purchasing");
        // Ticking "Keep these pages in my menu" saves the type with Show all menu items on.
        await confirm.getByTestId("confirm-keep-pages").click();
        let saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === settingsUrl);
        await confirm.getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);
        await expect(page.getByTestId("show-all-menu")).toHaveAttribute("aria-checked", "true");
        await expect(sidebarItem(page, "Purchasing")).toBeVisible();

        // Turned off again: the type decides, records or not. (Save is ready again once the first save is done.)
        await expect(page.getByTestId("save-business-type")).not.toHaveClass(/ant-btn-loading/);
        await page.getByTestId("show-all-menu").click();
        await page.getByTestId("save-business-type").click();
        // Only the menu changes this time, and it's still confirmed (the page stayed open after a save).
        await expect(page.locator(".ant-modal-confirm")).toContainText("Change the menu for everyone?");
        saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === settingsUrl);
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);
        await page.reload();
        await expect(page.getByTestId("business-type-services")).toBeChecked();
        await expect(page.getByRole("complementary").getByTestId("nav-section-stock")).toBeVisible();
        await expect(sidebarItem(page, "Purchasing")).toHaveCount(0);

        // The card says why, and "show them" brings the pages back.
        const note = page.getByTestId("menu-hidden-with-records");
        await expect(note).toContainText("Track stock on");
        await note.getByTestId("menu-show-them").click();
        await expect(page.getByTestId("show-all-menu")).toHaveAttribute("aria-checked", "true");
        await expect(note, "nothing left to warn about").toHaveCount(0);
        await page.getByTestId("save-business-type").click();
        saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === settingsUrl);
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);
        await expect(sidebarItem(page, "Purchasing")).toBeVisible();
    });
});
