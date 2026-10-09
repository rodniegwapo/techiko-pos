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

test.describe("Accounts, loans, assets and reviews as admin", () => {
    test.use({ account: "admin" });

    /** Saves the open modal whose title contains `title`. */
    const save = (page, title, button = "Save") =>
        page.locator(".ant-modal:visible", { hasText: title }).getByRole("button", { name: button }).click();
    const input = (scope, testId) => scope.getByTestId(testId).locator("input").or(scope.getByTestId(testId)).first();

    test("records a bank balance, a loan with repayment, equipment and an owner investment", async ({ page }) => {
        const stamp = Date.now();
        await page.goto(url("/finance/assets-and-loans"));

        // Bank account with a starting balance, then a newer balance.
        await page.getByTestId("add-account").click();
        await input(page.getByTestId("account-form"), "account-name").fill(`E2E Bank ${stamp}`);
        await input(page.getByTestId("account-form"), "account-balance").fill("15000");
        await save(page, "Add bank or e-wallet account");
        await expect(page.locator(".ant-message")).toContainText("Account saved");
        const accountRow = page.getByTestId("account-table").locator("tr", { hasText: `E2E Bank ${stamp}` });
        await expect(accountRow).toContainText("₱15,000.00");
        await accountRow.getByTestId("update-balance").click();
        await input(page.getByTestId("balance-form"), "balance-amount").fill("18250.50");
        await save(page, "Update balance");
        await expect(page.getByTestId("account-table").locator("tr", { hasText: `E2E Bank ${stamp}` })).toContainText("₱18,250.50");

        // Loan received by bank, then repaid in part with interest.
        await page.getByRole("tab", { name: "Loans" }).click();
        await page.getByTestId("add-loan").click();
        await input(page.getByTestId("loan-form"), "loan-lender").fill(`E2E Lender ${stamp}`);
        await input(page.getByTestId("loan-form"), "loan-principal").fill("20000");
        await page.getByTestId("loan-form").getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await save(page, "Record loan");
        const loanRow = page.getByTestId("loan-table").locator("tr", { hasText: `E2E Lender ${stamp}` });
        await expect(loanRow).toContainText("₱20,000.00");
        await loanRow.getByTestId("repay-loan").click();
        await input(page.getByTestId("repay-form"), "repay-principal").fill("5000");
        await input(page.getByTestId("repay-form"), "repay-interest").fill("250");
        await page.getByTestId("repay-form").getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await save(page, "Repay:");
        await expect(page.locator(".ant-message")).toContainText("Repayment recorded");
        await expect(page.getByTestId("loan-table").locator("tr", { hasText: `E2E Lender ${stamp}` })).toContainText("₱15,000.00");

        // Equipment.
        await page.getByRole("tab", { name: "Equipment & assets" }).click();
        await page.getByTestId("add-asset").click();
        await input(page.getByTestId("asset-form"), "asset-name").fill(`E2E Freezer ${stamp}`);
        await input(page.getByTestId("asset-form"), "asset-cost").fill("24000");
        await page.getByTestId("asset-form").getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await save(page, "Record equipment or other asset");
        await expect(page.getByTestId("asset-table")).toContainText(`E2E Freezer ${stamp}`);

        // Owner investment.
        await page.getByRole("tab", { name: "Owner investments" }).click();
        await page.getByTestId("add-investment").click();
        await input(page.getByTestId("investment-form"), "investment-amount").fill("10000");
        await page.getByTestId("investment-form").getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await save(page, "Record owner investment");
        await expect(page.locator(".ant-message")).toContainText("Investment saved");

        // They all reach the balance sheet.
        await page.goto(url("/finance/balance-sheet"));
        const { balanceSheet } = await pageProps(page);
        expect(balanceSheet.accounts.map((a) => a.name)).toContain(`E2E Bank ${stamp}`);
        expect(balanceSheet.loans.map((l) => l.lender)).toContain(`E2E Lender ${stamp}`);
        expect(balanceSheet.fixed_assets.map((a) => a.name)).toContain(`E2E Freezer ${stamp}`);
        await expect(page.getByTestId("owner-equity")).toBeVisible();
    });

    test("records another amount owed and shows it with accumulated profit on the balance sheet", async ({ page }) => {
        const stamp = Date.now();
        await page.goto(url("/finance/assets-and-loans?tab=liabilities"));

        await page.getByTestId("add-liability").click();
        await input(page.getByTestId("liability-form"), "liability-name").fill(`E2E VAT due ${stamp}`);
        await input(page.getByTestId("liability-form"), "liability-amount").fill("3200");
        await save(page, "Record amount owed");
        await expect(page.locator(".ant-message")).toContainText("Liability saved");
        await expect(page.getByTestId("liability-table")).toContainText(`E2E VAT due ${stamp}`);

        await page.goto(url("/finance/balance-sheet"));
        const { balanceSheet } = await pageProps(page);
        expect(balanceSheet.other_liabilities.map((l) => l.name)).toContain(`E2E VAT due ${stamp}`);
        for (const key of ["owner_contributions", "accumulated_profit", "other_changes"]) {
            await expect(page.getByTestId(`equity-${key}`)).toBeVisible();
        }
        // Owner's equity adds up: contributions + accumulated profit + other changes = net worth.
        const sum = balanceSheet.equity.reduce((total, row) => total + row.amount, 0);
        expect(sum).toBeCloseTo(balanceSheet.net_worth, 2);
    });

    test("writes and shows last month's business review", async ({ page }) => {
        await page.goto(url("/finance/reviews"));
        const generate = page.getByTestId("generate-review");
        if (await generate.count()) {
            await generate.click();
            await expect(page.locator(".ant-message")).toContainText("Review ready");
        }
        await expect(page.getByTestId("review")).toBeVisible();
        for (const id of ["went-well", "needs-attention", "actions"]) {
            await expect(page.getByTestId(id)).toBeVisible();
        }
    });

    test("overview has the question box and the statement explains each line", async ({ page }) => {
        await page.goto(url("/finance"));
        const panel = page.getByTestId("ask-panel");
        await expect(panel).toBeVisible();
        const { aiEnabled } = await pageProps(page);
        if (!aiEnabled) {
            await expect(panel).toContainText("not set up yet");
            const res = await postJson(page, url("/finance/ask"), { question: "How much profit did I make?" });
            expect(res.status).toBe(503);
        } else {
            await expect(page.getByTestId("ask-input")).toBeVisible();
        }

        await page.goto(url("/profit-loss"));
        // One "Explain" per statement line (not for the category details).
        expect(await page.getByTestId("pnl-statement").getByTestId("finance-explain").count()).toBeGreaterThanOrEqual(6);
    });
});

test.describe("Finance as cashier", () => {
    test.use({ account: "cashier" });

    test("has no Finance menu and is turned away from the pages", async ({ page, serverAs }) => {
        await page.goto(url("/dashboard"));
        await expect(page.getByRole("complementary").getByText("Finance", { exact: true })).toHaveCount(0);

        // Opened directly (no page to go back to), a refused page answers 403.
        const api = await serverAs("cashier");
        for (const path of ["/finance", "/finance/cash-flow", "/finance/balance-sheet", "/finance/receivables", "/finance/payables", "/finance/other-income", "/finance/assets-and-loans", "/finance/reviews", "/profit-loss"]) {
            const res = await api.get(url(path));
            expect(res.status(), `${path} should not open for a cashier`).toBe(403);
        }
    });
});
