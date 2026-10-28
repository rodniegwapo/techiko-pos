import { test, expect } from "../support/fixtures.js";

/**
 * Tables → floor plan, in the café of BusinessTypeSeeder (pos-food: dine-in on, no tables; the
 * seeder clears the ones a run adds). A table dragged to a new cell keeps it while more tables are
 * added, until the floor plan is saved.
 */
test.use({ account: "pos-food" });

const tablesUrl = "/domains/pos-food/dining-tables";

async function addTable(page, name) {
    await page.getByTestId("table-add").click();
    await page.getByTestId("table-name").fill(name);
    const saved = page.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname === tablesUrl);
    await page.locator(".ant-modal").getByRole("button", { name: /OK|Save/ }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.locator(".ant-modal")).toHaveCount(0);
}

const cell = (page, x, y) => page.getByTestId(`floor-cell-${x}-${y}`);

test("a table moved but not saved keeps its place when another table is added", async ({ page }) => {
    const id = Date.now().toString(36).slice(-4);
    await page.goto(tablesUrl);

    await addTable(page, `A${id}`);
    const tableA = page.locator("[draggable=true]").filter({ hasText: `A${id}` });
    await expect(tableA).toBeVisible();

    // Drag it three cells to the right of the first row's last free cell, without saving.
    await tableA.dragTo(cell(page, 5, 3));
    await expect(cell(page, 5, 3)).toContainText(`A${id}`);
    await expect(page.getByTestId("layout-save")).toBeEnabled();

    await addTable(page, `B${id}`);

    await expect(cell(page, 5, 3), "A stays where it was dragged").toContainText(`A${id}`);
    await expect(page.locator("[draggable=true]").filter({ hasText: `B${id}` })).toBeVisible();
    await expect(page.getByTestId("layout-save"), "the move still waits to be saved").toBeEnabled();
});
