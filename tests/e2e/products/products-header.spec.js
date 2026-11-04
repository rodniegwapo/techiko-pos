import { test, expect } from "../support/fixtures.js";
import { ProductsPage } from "../support/products.js";

/**
 * The Products list header: the low / out of stock counts sit on one line beside the title, even
 * when the toolbar beside them is full (the toolbar wraps instead).
 */
const SHOTS = process.env.PRODUCTS_HEADER_SHOTS;

test.describe("Products header", () => {
    test.use({ account: "wallet-manager" });

    for (const width of [1280, 1440, 1920]) {
        test(`the stock counts stay on the title's line at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 800 });
            await ProductsPage.open(page);

            const title = page.locator("h1", { hasText: /^\s*Products\s*$/ }).last();
            const tags = page.locator('[data-testid="stock-count-low"], [data-testid="stock-count-out"]');
            await expect(title).toBeVisible();
            if (SHOTS) await page.screenshot({ path: `${SHOTS}/products-header-${width}.png` });

            const count = await tags.count();
            test.skip(count === 0, "This store has no low or out of stock products to show.");

            const t = await title.boundingBox();
            for (let i = 0; i < count; i++) {
                const b = await tags.nth(i).boundingBox();
                // Same line: the tag's middle is within the title's height.
                const middle = b.y + b.height / 2;
                expect(middle, `tag ${i + 1} is on the title's line`).toBeGreaterThan(t.y);
                expect(middle, `tag ${i + 1} is on the title's line`).toBeLessThan(t.y + t.height);
            }
        });
    }
});
