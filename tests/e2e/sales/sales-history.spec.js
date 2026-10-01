import { test, expect } from "../support/fixtures.js";
import { pageProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

/**
 * The Sales History page: completed sales, a detail drawer with voided lines, receipt reprint and CSV.
 *
 * Reuses the paid sales E2EVoidLogSeeder makes for Jollibee — each has one voided line and an
 * invoice number of its own, so a search finds exactly one. The range covers yesterday too, because
 * those sales are dated a couple of hours before the seed and a run just after midnight would miss them.
 */

const voided = () => fixtureIds().voidLogs.recent[0];
const historyPath = "/domains/jollibee-corp/sales-history";

const rangeQuery = () => {
    const day = (d) => d.toISOString().slice(0, 10);
    const today = new Date();
    const yesterday = new Date(Date.now() - 24 * 60 * 60 * 1000);
    return `?start_date=${day(yesterday)}&end_date=${day(today)}`;
};

const rows = (page) => page.locator("[data-testid=sales-history-table] .ant-table-tbody tr.ant-table-row");

async function searchInvoice(page, invoice) {
    const found = page.waitForResponse((r) => new URL(r.url()).searchParams.get("search") === invoice);
    await page.getByPlaceholder("Invoice # or customer").fill(invoice);
    await found;
}

test.describe("Sales history (admin)", () => {
    test.use({ account: "admin" });

    test("is reachable from the sidebar", async ({ page }) => {
        await page.goto("/domains/jollibee-corp/dashboard");
        await page.getByRole("menuitem", { name: "Sales History" }).click();

        await expect(page).toHaveURL(/\/domains\/jollibee-corp\/sales-history/);
        await expect(page.getByTestId("sales-history-table")).toBeVisible();
        // An Inertia visit leaves #app data-page at the first page, so check what is on screen.
        await expect(page.getByText("All sales", { exact: true })).toBeVisible();
    });

    test("finds a sale by invoice and shows its voided line in the drawer", async ({ page }) => {
        const sale = voided();
        await page.goto(historyPath + rangeQuery());
        await searchInvoice(page, sale.invoice_number);

        await expect(rows(page)).toHaveCount(1);
        const row = rows(page).first();
        await expect(row).toContainText(sale.invoice_number);
        await expect(row).toContainText("1 voided");

        await row.click();
        const detail = page.getByTestId("sale-detail");
        await expect(detail).toBeVisible();
        await expect(detail.locator(".ant-table-row", { hasText: sale.product })).toContainText("Voided");
        await expect(detail).toContainText(sale.reason);
        await expect(detail).toContainText(sale.approver);

        // The reprint source is rendered (clicking it opens the browser's print dialog, so it isn't clicked).
        await expect(page.locator("#sale-receipt-print-area")).toContainText(sale.invoice_number, { useInnerText: false });
        await expect(page.getByRole("button", { name: "Reprint receipt" })).toBeEnabled();
    });

    test("exports the filtered sales as CSV", async ({ page }) => {
        const sale = voided();
        await page.goto(historyPath + rangeQuery());
        await searchInvoice(page, sale.invoice_number);

        const download = page.waitForEvent("download");
        await page.getByTestId("sales-history-export").click();
        const file = await download;

        expect(file.suggestedFilename()).toMatch(/^sales-history-jollibee-corp-.*\.csv$/);
        const csv = await (await file.createReadStream()).toArray();
        const text = Buffer.concat(csv).toString("utf8");
        expect(text).toContain("invoice_number,transaction_date,cashier");
        expect(text).toContain(sale.invoice_number);
    });
});

test.describe("Sales history (cashier)", () => {
    test.use({ account: "cashier" });

    test("only lists the cashier's own sales and offers no export", async ({ page }) => {
        await page.goto(historyPath + rangeQuery());

        const props = await pageProps(page);
        expect(props.restrictedToOwnSales).toBe(true);
        expect(props.options.cashiers).toEqual([]);

        const me = props.auth.user.data.name;
        for (const sale of props.items.data) {
            expect(sale.cashier_name).toBe(me);
        }

        await expect(page.getByText("My sales")).toBeVisible();
        await expect(page.getByTestId("sales-history-export")).toHaveCount(0);
    });
});
