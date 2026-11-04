import { test, expect } from "../support/fixtures.js";
import { SalesPage } from "../support/sales.js";

/**
 * The Sales screen's top-right corner: the signed-in user's clock ("Clock in" / "In since …") and
 * their drawer. Admins have the store switcher there, so the buttons sit right below it.
 */
const SHOTS = process.env.TILL_CORNER_SHOTS;

for (const layout of ["classic", "coffeeshop"]) {
    test.describe(`Till corner (${layout})`, () => {
        test.describe("as a cashier", () => {
            test.use({ account: "wallet-cashier" });

            test("shows my clock and my drawer in the corner", async ({ page }) => {
                await SalesPage.open(page, { layout });
                const corner = page.getByTestId("till-corner");
                await expect(corner).toBeVisible();
                await expect(corner.getByTestId("timeclock-status")).toHaveText(/^(Clock in|In since \d{1,2}:\d{2} [AP]M)$/);
                await expect(corner.getByTestId("till-drawer-action")).toHaveText(/Open my drawer|Drawer open|End it on Today's drawer|Reopen the day/);

                // In the corner: its right edge lines up with the page's.
                const box = await corner.boundingBox();
                const viewport = page.viewportSize();
                expect(viewport.width - (box.x + box.width)).toBeLessThan(40);
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/cashier-${layout}.png` });
            });
        });

        test.describe("as an admin", () => {
            test.use({ account: "admin" });

            test("puts the buttons right below the store switcher", async ({ page }) => {
                await SalesPage.open(page, { layout });
                const wrap = page.getByTestId("layout-corner");
                const badge = wrap.locator(".location-badge");
                const corner = page.getByTestId("till-corner");
                await expect(badge).toBeVisible();
                await expect(corner).toBeVisible();

                const b = await badge.boundingBox();
                const c = await corner.boundingBox();
                expect(c.y).toBeGreaterThanOrEqual(b.y + b.height - 1);
                expect(Math.abs(c.x + c.width - (b.x + b.width))).toBeLessThan(4);
                if (SHOTS) await page.screenshot({ path: `${SHOTS}/admin-${layout}.png` });
            });
        });
    });
}
