import { test, expect } from "../support/fixtures.js";
import { USERS } from "../support/users.js";
import { pageComponent, pageProps, postJson } from "../support/inertia.js";

const DOMAIN = USERS.admin.domain;
const url = (path) => `/domains/${DOMAIN}${path}`;

/** Picks an option in the open ant-design select dropdown. */
async function pickOption(page, label) {
    await page.locator(".ant-select-dropdown:visible .ant-select-item-option", { hasText: label }).first().click();
}

for (const role of ["admin", "manager"]) {
    test.describe(`Finance as ${role}`, () => {
        test.use({ account: role });

        test("overview shows the headline numbers, health check and suggestions", async ({ page }) => {
            await page.goto(url("/finance"));

            expect(await pageComponent(page)).toBe("Finance/Dashboard");
            await expect(page.getByRole("heading", { name: /How is the business doing/ })).toBeVisible();

            const kpis = page.getByTestId("finance-kpis");
            for (const label of ["Revenue (sales less VAT)", "Gross profit", "Operating expenses", "Net profit", "Money received", "Customers owe you", "You owe suppliers", "Inventory value (today)"]) {
                await expect(kpis.getByText(label, { exact: true })).toBeVisible();
            }

            const health = page.getByTestId("finance-health");
            for (const label of ["Sales", "Money received", "Inventory", "Customer credit", "Supplier bills", "Expenses"]) {
                await expect(health.getByText(label, { exact: true })).toBeVisible();
            }
            await expect(page.getByText("not professional financial advice")).toBeVisible();

            const { overview } = await pageProps(page);
            const c = overview.current;
            // Net profit is worked out from the parts shown, to the centavo.
            expect(c.net_profit).toBeCloseTo(c.gross_profit - c.operating_expenses + c.other_income - c.other_expenses, 2);
        });

        test("filters with the shared filter dropdown and shows the active period", async ({ page }) => {
            await page.goto(url("/finance?period=year"));

            await expect(page.getByTestId("finance-period-label")).toContainText("This year");
            await expect(page.locator(".ant-tag", { hasText: "This year" })).toBeVisible();

            // The same FilterDropdown as the other list pages.
            await page.locator(".ant-badge button").first().click();
            await expect(page.locator(".ant-popover:visible").getByText("Date", { exact: true })).toBeVisible();

            const props = await pageProps(page);
            expect(props.filters.key).toBe("year");
            expect(props.filters.start_date).toMatch(/-01-01$/);
        });

        test("tabs open every Finance page", async ({ page }) => {
            await page.goto(url("/finance?period=month"));
            const nav = page.getByTestId("finance-nav");

            await nav.getByRole("link", { name: "Income statement" }).click();
            await expect(page).toHaveURL(/\/profit-loss\?(.*&)?preset=this_month/);
            await expect(page.getByTestId("pnl-statement")).toBeVisible();

            await page.getByTestId("finance-nav").getByRole("link", { name: "Cash flow" }).click();
            await expect(page.getByTestId("cash-flow-statement")).toBeVisible();
            await expect(page.getByTestId("profit-vs-cash")).toBeVisible();

            await page.getByTestId("finance-nav").getByRole("link", { name: "Balance sheet" }).click();
            await expect(page.getByTestId("net-worth")).toBeVisible();

            await page.getByTestId("finance-nav").getByRole("link", { name: "Customer credit" }).click();
            await expect(page.getByRole("heading", { name: /How much do my customers owe me/ })).toBeVisible();

            await page.getByTestId("finance-nav").getByRole("link", { name: "Supplier bills" }).click();
            await expect(page.getByTestId("payables-summary")).toBeVisible();

            await page.getByTestId("finance-nav").getByRole("link", { name: "Other income" }).click();
            await expect(page.getByTestId("income-table")).toBeVisible();

            await page.getByTestId("finance-nav").getByRole("link", { name: "Expenses" }).click();
            await expect(page).toHaveURL(/\/expenses\?/);
        });

        test("explain buttons follow whether the AI assistant is set up", async ({ page }) => {
            await page.goto(url("/finance"));
            const { aiEnabled } = await pageProps(page);
            const explain = page.getByTestId("finance-explain").first();

            if (!aiEnabled) {
                await expect(explain).toBeDisabled();
                const res = await postJson(page, url("/finance/explain"), { topic: "cash_flow" });
                expect(res.status).toBe(503);
                return;
            }

            await explain.click();
            await expect(page.locator(".ant-drawer")).toBeVisible();
            await expect(page.getByTestId("finance-explain-answer").or(page.locator(".ant-drawer .ant-alert"))).toBeVisible({ timeout: 60_000 });
        });
    });
}

test.describe("Supplier bills and other income as admin", () => {
    test.use({ account: "admin" });

    test("adds a supplier, records a bill and pays it off", async ({ page }) => {
        const supplier = `E2E Supplier ${Date.now()}`;
        await page.goto(url("/finance/payables?status=all"));

        // New supplier, from the bill form.
        await page.getByTestId("add-bill").click();
        const billForm = page.getByTestId("bill-form");
        await billForm.getByRole("button", { name: "New" }).click();
        await page.getByTestId("supplier-name").locator("input").or(page.getByTestId("supplier-name")).first().fill(supplier);
        await page.locator(".ant-modal:visible", { hasText: "Add supplier" }).getByRole("button", { name: "Save" }).click();
        await expect(page.locator(".ant-modal:visible", { hasText: "Add supplier" })).toHaveCount(0);

        await billForm.getByTestId("bill-supplier").click();
        await pickOption(page, supplier);
        await billForm.getByTestId("bill-amount").locator("input").or(billForm.getByTestId("bill-amount")).first().fill("1500");
        await page.locator(".ant-modal:visible", { hasText: "Record supplier bill" }).getByRole("button", { name: "Save" }).click();
        await expect(page.locator(".ant-message")).toContainText("Bill recorded");

        const row = page.getByTestId("bill-table").locator("tr", { hasText: supplier });
        await expect(row).toContainText("Unpaid");

        // Pay it in full by bank transfer.
        await row.getByTestId("pay-bill").click();
        const payment = page.getByTestId("payment-form");
        await payment.getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await page.locator(".ant-modal:visible", { hasText: "Record payment" }).getByRole("button", { name: "Save payment" }).click();
        await expect(page.locator(".ant-message")).toContainText("Payment recorded");
        await expect(page.getByTestId("bill-table").locator("tr", { hasText: supplier })).toContainText("Paid");
    });

    test("records other income", async ({ page }) => {
        const description = `E2E stall rent ${Date.now()}`;
        await page.goto(url("/finance/other-income?period=today"));

        await page.getByTestId("add-income").click();
        const form = page.getByTestId("income-form");
        await form.getByTestId("income-amount").locator("input").or(form.getByTestId("income-amount")).first().fill("250");
        await form.getByTestId("income-description").fill(description);
        await form.getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await page.locator(".ant-modal:visible", { hasText: "Record other income" }).getByRole("button", { name: "Save" }).click();

        await expect(page.locator(".ant-message")).toContainText("Income recorded");
        await expect(page.getByTestId("income-table")).toContainText(description);
    });
});

test.describe("Finance as cashier", () => {
    test.use({ account: "cashier" });

    test("has no Finance menu and is turned away from the pages", async ({ page, serverAs }) => {
        await page.goto(url("/dashboard"));
        await expect(page.getByRole("complementary").getByText("Finance", { exact: true })).toHaveCount(0);

        // Opened directly (no page to go back to), a refused page answers 403.
        const api = await serverAs("cashier");
        for (const path of ["/finance", "/finance/cash-flow", "/finance/balance-sheet", "/finance/receivables", "/finance/payables", "/finance/other-income", "/profit-loss"]) {
            const res = await api.get(url(path));
            expect(res.status(), `${path} should not open for a cashier`).toBe(403);
        }
    });
});
