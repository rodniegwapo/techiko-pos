import { test, expect } from "../support/fixtures.js";
import { dineInOpen } from "../support/comingSoon.js";
import { SalesPage } from "../support/sales.js";

/**
 * Dine-in at the till, in the café of BusinessTypeSeeder (pos-food: dine-in on, tables T1 and T2,
 * the admin's shift open, open carts and tabs voided each run). The cashier picks Dine-in or
 * Take-out; a table is seated, served, sent to the kitchen and paid, then waits to be cleared;
 * take-out is paid first and its kitchen ticket comes up to print.
 * The tests share the café's tables and carts, so they run in order.
 */
test.describe.configure({ mode: "serial" });
test.use({ account: "pos-food" });
// Dine-in is coming soon (FEATURE_DINE_IN off): these skip until it opens.
test.beforeAll(async () => test.skip(!(await dineInOpen()), "dine-in is coming soon"));

const domain = "pos-food";

/** The Sales page in the classic layout, with the SalesPage helpers (they don't depend on the domain). */
async function openSales(page) {
    await page.addInitScript(([key, value]) => localStorage.setItem(key, value), [`sales_layout_mode_${domain}`, "classic"]);
    const sales = new SalesPage(page, "classic");
    await Promise.all([sales.waitForProducts(), page.goto(`/domains/${domain}/sales`)]);
    await expect(sales.searchBox).toBeVisible();
    return sales;
}

test("a table is seated, served, sent to the kitchen, paid, and cleared for the next guests", async ({ page }) => {
    const sales = await openSales(page);

    // Starts on Take-out, and says so.
    await expect(page.getByTestId("order-type-take_out")).toHaveAttribute("aria-pressed", "true");
    await expect(page.getByTestId("order-next-step")).toContainText("Take-out");

    // Dine-in: pick a table.
    await page.getByTestId("order-type-dine_in").click();
    const tables = page.getByTestId("tables-screen");
    await expect(tables).toBeVisible();
    await expect(page.getByTestId("tables-hint")).toContainText("Tap a green table to seat guests");
    await tables.getByTestId("table-T1").click();
    await page.getByTestId("tab-open-confirm").click();

    // The tab: Dine-in, with the next step and Send to kitchen waiting for items.
    await expect(page.getByTestId("tab-label")).toHaveText("T1");
    await expect(page.getByTestId("order-type-dine_in")).toHaveAttribute("aria-pressed", "true");
    await expect(page.getByTestId("order-next-step")).toContainText("Send to kitchen");
    await expect(page.getByTestId("tab-send")).toBeDisabled();

    await sales.addProduct("Fries");
    await page.getByTestId("tab-send").click();
    await expect(page.getByTestId("kitchen-ticket")).toContainText("T1");
    await expect(page.getByTestId("kitchen-ticket")).toContainText("Fries");
    await page.getByTestId("kitchen-ticket-done").click();

    // Paid: back on Take-out (nothing pops up), and T1 needs clearing.
    const paid = await sales.pay({ amountReceived: 100 });
    expect(paid?.status(), "payment response").toBe(200);
    expect((await paid.json()).kitchen_ticket, "a table's order was already sent").toBeNull();
    await expect(page.getByTestId("order-type-take_out")).toHaveAttribute("aria-pressed", "true");
    await expect(tables).toBeHidden();

    await page.getByTestId("order-type-dine_in").click();
    await expect(tables.getByTestId("table-cleaning-T1")).toHaveText("Needs clearing");
    await tables.getByTestId("table-T1").click();
    await page.getByTestId("table-ready").click();
    await expect(tables.getByTestId("table-T1")).toContainText("4 seats");
});

test("take-out is paid first and its kitchen ticket comes up to print", async ({ page }) => {
    const sales = await openSales(page);
    await expect(page.getByTestId("order-type-take_out")).toHaveAttribute("aria-pressed", "true");

    await sales.addProduct("Fries");
    const paid = await sales.pay({ amountReceived: 100 });
    expect(paid?.status(), "payment response").toBe(200);

    const ticket = page.getByTestId("kitchen-ticket");
    await expect(ticket).toContainText("Take-out");
    await expect(ticket).toContainText("Fries");
    await expect(page.getByTestId("kitchen-ticket-print")).toBeVisible();
    await page.getByTestId("kitchen-ticket-done").click();
    await expect(ticket).toBeHidden();
});

test("Take-out leaves an open tab for its table, and Dine-in picks it back up", async ({ page }) => {
    await openSales(page);
    await page.getByTestId("order-type-dine_in").click();
    const tables = page.getByTestId("tables-screen");
    await tables.getByTestId("table-T2").click();
    await page.getByTestId("tab-open-confirm").click();
    await expect(page.getByTestId("tab-label")).toHaveText("T2");

    await page.getByTestId("order-type-take_out").click();
    await expect(page.getByTestId("tab-bar")).toHaveCount(0);
    await expect(page.getByTestId("order-type-take_out")).toHaveAttribute("aria-pressed", "true");

    await page.getByTestId("order-type-dine_in").click();
    await expect(tables.getByTestId("table-T2"), "still occupied").toContainText(/\dm/);
    await tables.getByTestId("table-T2").click();
    await expect(page.getByTestId("tab-label")).toHaveText("T2");
});
