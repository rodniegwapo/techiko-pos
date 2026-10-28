import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps } from "../support/inertia.js";
import { newProductValues } from "../support/products.js";

/**
 * Settings → Business type presets the product form (useBusinessType.js), with one organization per
 * type (BusinessTypeSeeder): pos-retail, pos-food, pos-grocery, pos-services and pos-other, each with
 * its admin and products that fit it. Food has Size and Sugar level modifiers on its drinks.
 *
 * Only the pos-other tests change a type, and they run one after another, so the other
 * organizations keep theirs while the suite runs in parallel.
 */

const url = (type, path) => `/domains/pos-${type}${path}`;

/** What Create Product starts with for each type. */
const PRESETS = {
    retail: { trackStock: true, soldType: "Piece", modifiersOpen: false },
    food: { trackStock: false, soldType: "Piece", modifiersOpen: true },
    grocery: { trackStock: true, soldType: "Kilo", modifiersOpen: false },
    services: { trackStock: false, soldType: "Piece", modifiersOpen: false },
    other: { trackStock: true, soldType: null, modifiersOpen: true },
};

const trackStock = (page) => page.getByRole("switch", { name: "Track stock" });
const soldTypeRadio = (page, name) => page.locator(".ant-radio-wrapper").filter({ hasText: new RegExp(`^\\s*${name}\\s*$`) });
const checkedSoldTypes = (page) => page.locator(".ant-radio-wrapper-checked").filter({ hasText: /^(Piece|Box|Pack|Dozen|Kilo|Liter)$/ });

/** Opens Create Product in the organization for `org` and checks it starts as `type` presets it. */
async function expectPreset(page, org, type = org) {
    const preset = PRESETS[type];
    await page.goto(url(org, "/products/create"));

    await expect(trackStock(page)).toHaveAttribute("aria-checked", String(preset.trackStock));
    if (preset.soldType) {
        await expect(soldTypeRadio(page, preset.soldType)).toHaveClass(/ant-radio-wrapper-checked/);
    } else {
        await expect(checkedSoldTypes(page), "nothing preset: the owner picks").toHaveCount(0);
    }
    await expect(page.getByTestId("modifiers-field")).toHaveCount(preset.modifiersOpen ? 1 : 0);
    await expect(page.getByTestId("show-modifiers")).toHaveCount(preset.modifiersOpen ? 0 : 1);
}

for (const type of ["retail", "food", "grocery", "services"]) {
    test.describe(`${type} business`, () => {
        test.use({ account: `pos-${type}` });

        test("Create Product starts with the type's defaults", async ({ page }) => {
            await expectPreset(page, type);
            expect((await pageProps(page)).businessType).toBe(type);
        });

        test("Settings shows the type picked", async ({ page }) => {
            await page.goto(url(type, "/settings"));
            await expect(page.getByTestId(`business-type-${type}`)).toBeChecked();
        });
    });
}

test.describe("retail business", () => {
    test.use({ account: "pos-retail" });

    test("modifiers are one click away", async ({ page }) => {
        await page.goto(url("retail", "/products/create"));
        await page.getByTestId("show-modifiers").click();
        await expect(page.getByTestId("modifiers-field")).toBeVisible();
    });
});

test.describe("food business", () => {
    test.use({ account: "pos-food" });

    test("points to dine-in tables on Settings", async ({ page }) => {
        await page.goto(url("food", "/settings"));
        await expect(page.getByText("Serving at tables?")).toBeVisible();
    });

    test("a drink keeps its Size and Sugar level modifiers on Edit", async ({ page }) => {
        await page.goto(url("food", "/products"));
        const props = await pageProps(page);
        const milkTea = props.items.data.find((p) => p.name === "Classic Milk Tea");
        expect(milkTea, "seeded Classic Milk Tea").toBeTruthy();

        await page.goto(url("food", `/products/${milkTea.id}/edit`));
        const field = page.getByTestId("modifiers-field");
        await expect(field).toContainText("Size");
        await expect(field).toContainText("Sugar level");
    });
});

test.describe("grocery business", () => {
    test.use({ account: "pos-grocery" });

    test("a product sold by the kilo saves with a part-kilo low stock level", async ({ page }) => {
        const values = newProductValues("Rice");
        await page.goto(url("grocery", "/products/create"));

        await page.getByPlaceholder("Enter product name").fill(values.name);
        await page.getByPlaceholder("Enter price").fill("52");
        await page.getByPlaceholder("Enter SKU").fill(values.sku);
        await page.getByPlaceholder("Enter barcode").fill(values.barcode);
        const level = page.getByLabel("Low stock level");
        await expect(page.locator(".ant-input-number-group-addon").filter({ hasText: "kg" })).toBeVisible();
        await level.fill("2.5");

        const posted = page.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname === url("grocery", "/products"));
        await page.getByRole("button", { name: "Create Product" }).last().click();
        expect((await posted).status()).toBeLessThan(400);

        await page.goto(url("grocery", `/products?search=${encodeURIComponent(values.name)}`));
        const saved = (await pageProps(page)).items.data.find((p) => p.name === values.name);
        expect(saved, "the new product is listed").toBeTruthy();
        expect(saved.sold_type).toBe("Kilo");
        expect(Number(saved.reorder_level)).toBe(2.5);
    });
});

test.describe("other / mixed business", () => {
    // Changes this organization's type, so its tests run in order and nothing else uses it.
    test.describe.configure({ mode: "serial" });
    test.use({ account: "pos-other" });

    test.afterAll(async ({ serverAs }) => {
        const api = await serverAs("pos-other");
        await apiJson(api, "PATCH", url("other", "/settings"), { business_type: "other" });
    });

    test("Create Product shows every field and presets no sold type", async ({ page }) => {
        await expectPreset(page, "other");
    });

    test("switching to Retail on Settings changes new products, not existing ones", async ({ page }) => {
        await page.goto(url("other", "/settings"));
        await page.getByTestId("business-type-retail").click();

        // A change is confirmed first; Cancel saves nothing.
        await page.getByTestId("save-business-type").click();
        const confirm = page.locator(".ant-modal-confirm");
        await expect(confirm).toContainText("Change the business type to Retail store?");
        await expect(confirm).toContainText("Products and sales you already have don't change.");
        await confirm.getByRole("button", { name: "Cancel" }).click();
        await page.reload();
        await expect(page.getByTestId("business-type-other"), "nothing was saved").toBeChecked();

        await page.getByTestId("business-type-retail").click();
        await page.getByTestId("save-business-type").click();
        const saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === url("other", "/settings"));
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);
        await expect(page.getByTestId("business-type-retail")).toBeChecked();
        await expect(page.getByTestId("business-type-changed")).toContainText("Changed from Other / mixed to Retail store by Demo Mixed Store Admin on");

        await expectPreset(page, "other", "retail");

        // Iced Coffee already has modifiers: they still show although Retail collapses the field.
        await page.goto(url("other", "/products"));
        const coffee = (await pageProps(page)).items.data.find((p) => p.name === "Iced Coffee");
        expect(coffee, "seeded Iced Coffee").toBeTruthy();
        await page.goto(url("other", `/products/${coffee.id}/edit`));
        await expect(page.getByTestId("modifiers-field")).toContainText("Size");
    });
});

test.describe("a business that never picked its type", () => {
    // E2EPlanSeeder leaves the plan organizations without one.
    test.use({ account: "plan-free" });

    test("its admin is asked what the business sells, and Settings is one click away", async ({ page }) => {
        await page.goto("/domains/e2e-plan-free/products");
        const banner = page.getByTestId("business-type-banner");
        await expect(banner).toContainText("What does your business sell?");
        expect((await pageProps(page)).businessType, "reads as retail meanwhile").toBe("retail");

        await page.getByTestId("business-type-banner-link").click();
        await expect(page).toHaveURL(/\/domains\/e2e-plan-free\/settings/);
        await expect(page.getByTestId("business-type")).toBeVisible();
        await expect(banner, "not repeated on Settings itself").toHaveCount(0);
    });

    test("closing the banner keeps it closed for the session", async ({ page }) => {
        await page.goto("/domains/e2e-plan-free/products");
        const banner = page.getByTestId("business-type-banner");
        await banner.locator(".ant-alert-close-icon").click();
        await expect(banner).toHaveCount(0);

        await page.reload();
        await expect(page.getByTestId("business-type-banner")).toHaveCount(0);
    });
});

test.describe("a business that picked its type", () => {
    test.use({ account: "pos-retail" });

    test("sees no banner", async ({ page }) => {
        await page.goto("/domains/pos-retail/products");
        await expect(page.getByText("What does your business sell?")).toHaveCount(0);
    });
});

/** The sidebar, less the pages each type tidies away (useBusinessType.js hiddenMenuKeys). */
const sidebar = (page) => page.getByRole("complementary");
const menuItem = (page, title) => sidebar(page).getByText(title, { exact: true });

async function openInventory(page) {
    await menuItem(page, "Inventory").click();
}

for (const [type, showsTables] of [["retail", false], ["grocery", false], ["food", true]]) {
    test.describe(`${type} sidebar`, () => {
        test.use({ account: `pos-${type}` });

        test(`${showsTables ? "shows" : "leaves out"} Tables`, async ({ page }) => {
            await page.goto(url(type, "/products"));
            await expect(sidebar(page).getByTestId("nav-section-stock")).toBeVisible();
            await expect(menuItem(page, "Tables")).toHaveCount(showsTables ? 1 : 0);
        });
    });
}

test.describe("services sidebar", () => {
    // Turns "Show all menu items" on and off for pos-services, so these run in order.
    test.describe.configure({ mode: "serial" });
    test.use({ account: "pos-services" });

    const setShowAll = async (serverAs, on) => {
        const api = await serverAs("pos-services");
        await apiJson(api, "PATCH", url("services", "/settings"), { show_all_menu: on });
    };
    test.beforeAll(async ({ serverAs }) => setShowAll(serverAs, false));
    test.afterAll(async ({ serverAs }) => setShowAll(serverAs, false));

    test("leaves out tables and the stock pages a services business doesn't use", async ({ page }) => {
        await page.goto(url("services", "/products"));
        await expect(sidebar(page).getByTestId("nav-section-stock")).toBeVisible();
        await expect(menuItem(page, "Tables")).toHaveCount(0);
        await expect(menuItem(page, "Purchasing")).toHaveCount(0);

        await openInventory(page);
        await expect(menuItem(page, "Stock Adjustments")).toHaveCount(0);
        await expect(menuItem(page, "Movements")).toHaveCount(0);
        await expect(menuItem(page, "Locations"), "the rest of Inventory stays").toBeVisible();
    });

    test("page search still finds a tidied page and opens it", async ({ page }) => {
        await page.goto(url("services", "/products"));
        await page.keyboard.press("Control+k");
        await page.getByTestId("nav-search-input").fill("Purchasing");
        const item = page.getByTestId("nav-search-item").filter({ hasText: "Purchasing" }).first();
        await expect(item.getByTestId("nav-search-tidied")).toHaveText("Not in your menu");
        await item.click();
        await expect(page).toHaveURL(/\/domains\/pos-services\/purchasing/);
    });

    test("Show all menu items brings them back", async ({ page }) => {
        await page.goto(url("services", "/settings"));
        await expect(page.getByTestId("show-all-menu")).toHaveAttribute("aria-checked", "false");
        // It uses none of the pages a services business leaves out, so there's nothing kept to explain.
        await expect(page.getByTestId("menu-kept-in-use")).toHaveCount(0);
        await page.getByTestId("show-all-menu").click();
        await page.getByTestId("save-business-type").click();
        const saved = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === url("services", "/settings"));
        await page.locator(".ant-modal-confirm").getByRole("button", { name: "Change" }).click();
        expect((await saved).status()).toBeLessThan(400);

        await page.goto(url("services", "/products"));
        await expect(menuItem(page, "Purchasing")).toBeVisible();
        await openInventory(page);
        await expect(menuItem(page, "Stock Adjustments")).toBeVisible();
    });
});

test.describe("a manager", () => {
    test.use({ account: "pos-retail-manager" });

    test("sees the business type but can't change it", async ({ page }) => {
        await page.goto(url("retail", "/settings"));
        await expect(page.getByTestId("business-type-retail")).toBeChecked();
        await expect(page.getByTestId("business-type-food")).toBeDisabled();
        await expect(page.getByTestId("show-all-menu")).toBeDisabled();
        await expect(page.getByTestId("save-business-type")).toHaveCount(0);
        await expect(page.getByTestId("business-type-read-only")).toContainText("Only an owner or admin can change this.");
    });
});
