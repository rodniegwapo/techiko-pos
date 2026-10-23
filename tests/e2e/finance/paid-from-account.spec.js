import { test, expect } from "../support/fixtures.js";
import { USERS } from "../support/users.js";
import { pageProps } from "../support/inertia.js";

const DOMAIN = USERS.admin.domain;
const url = (path) => `/domains/${DOMAIN}${path}`;

/** Picks an option in the open ant-design select dropdown. */
async function pickOption(page, label) {
    // The list is virtual: an option far down (e.g. after many test runs' accounts) isn't in the
    // page until scrolled to.
    const dropdown = page.locator(".ant-select-dropdown:visible").last();
    const option = dropdown.locator(".ant-select-item-option", { hasText: label }).first();
    const holder = dropdown.locator(".rc-virtual-list-holder");
    for (let i = 0; i < 200 && !(await option.isVisible()); i++) {
        await holder.evaluate((el) => (el.scrollTop += 120));
        await page.waitForTimeout(40);
    }
    await option.click();
}
const input = (scope, testId) => scope.getByTestId(testId).locator("input").or(scope.getByTestId(testId)).first();
const modal = (page, title) => page.locator(".ant-modal:visible", { hasText: title });

// Paid by bank transfer or e-wallet: which account the money left, shown in the lists and next to
// the account's balance (which itself stays as entered).
test.describe("Paying from a bank account as admin", () => {
    test.use({ account: "admin" });

    test("a supplier payment and an expense name the account, and the account shows what was paid out", async ({ page }) => {
        const stamp = Date.now();
        const account = `E2E Paid Bank ${stamp}`;
        const supplier = `E2E Acct Supplier ${stamp}`;
        const description = `E2E bank expense ${stamp}`;
        const yesterday = new Date(Date.now() - 86_400_000).toLocaleDateString("en-CA");

        // A bank account whose last balance was entered yesterday.
        await page.goto(url("/finance/assets-and-loans"));
        await page.getByTestId("add-account").click();
        const accountForm = page.getByTestId("account-form");
        await input(accountForm, "account-name").fill(account);
        await input(accountForm, "account-balance").fill("10000");
        const asOf = modal(page, "Add bank or e-wallet account").locator(".ant-picker input");
        await asOf.click();
        await asOf.fill(yesterday);
        await asOf.press("Enter");
        await modal(page, "Add bank or e-wallet account").getByRole("button", { name: "Save" }).click();
        await expect(page.locator(".ant-message")).toContainText("Account saved");

        // A supplier bill paid from it.
        await page.goto(url("/finance/payables?status=all"));
        await page.getByTestId("add-bill").click();
        const billForm = page.getByTestId("bill-form");
        await billForm.getByRole("button", { name: "New" }).click();
        await input(page, "supplier-name").fill(supplier);
        await modal(page, "Add supplier").getByRole("button", { name: "Save" }).click();
        await expect(modal(page, "Add supplier")).toHaveCount(0);
        await billForm.getByTestId("bill-supplier").click();
        await pickOption(page, supplier);
        await input(billForm, "bill-amount").fill("1200");
        await modal(page, "Record supplier bill").getByRole("button", { name: "Save" }).click();
        await expect(page.locator(".ant-message")).toContainText("Bill recorded");

        await page.getByTestId("bill-table").locator("tr", { hasText: supplier }).getByTestId("pay-bill").click();
        const payment = page.getByTestId("payment-form");
        // No account to pick while paying from the cash register.
        await expect(payment.getByTestId("paid-from-account")).toHaveCount(0);
        await payment.getByTestId("payment-method").click();
        await pickOption(page, "Bank transfer");
        await payment.getByTestId("paid-from-account").click();
        await pickOption(page, account);
        await modal(page, "Record payment").getByRole("button", { name: "Save payment" }).click();
        await expect(page.locator(".ant-message")).toContainText("Payment recorded");

        // data-page holds the props of the first load only.
        await page.reload();
        const bill = (await pageProps(page)).bills.data.find((b) => b.supplier?.name === supplier);
        expect(bill.payments[0].financial_account.name).toBe(account);
        const billRow = page.getByTestId("bill-table").locator("tr", { hasText: supplier });
        await billRow.locator(".ant-table-row-expand-icon").click();
        await expect(page.getByTestId("bill-table")).toContainText(`Bank transfer · ${account}`);

        // An expense paid from it.
        await page.goto(url("/expenses"));
        await page.getByTestId("expenses-create").click();
        const expenseForm = page.getByTestId("expense-form");
        await input(expenseForm, "expense-amount").fill("300");
        await expenseForm.getByTestId("expense-description").fill(description);
        await expenseForm.getByTestId("expense-category").click();
        await page.keyboard.type("Utilities");
        await page.keyboard.press("Enter");
        await expect(expenseForm.getByTestId("expense-category")).toContainText("Utilities");
        await expenseForm.getByTestId("expense-payment-method").click();
        await pickOption(page, "Bank transfer");
        await expenseForm.getByTestId("paid-from-account").click();
        await pickOption(page, account);
        await page.getByTestId("expense-submit").click();
        await expect(page.getByTestId("expenses-table")).toContainText(description);
        await expect(page.getByTestId("expenses-table").locator("tr", { hasText: description })).toContainText(`Bank transfer · ${account}`);

        // The account's balance stays as entered; what was paid out since shows beside it.
        await page.goto(url("/finance/assets-and-loans"));
        const accountRow = page.getByTestId("account-table").locator("tr", { hasText: account });
        await expect(accountRow).toContainText("₱10,000.00");
        await expect(accountRow.getByTestId("account-paid-out")).toContainText("₱1,500.00");
    });

    test("switching an expense to cash on hand drops the account", async ({ page }) => {
        await page.goto(url("/expenses"));
        await page.getByTestId("expenses-create").click();
        const expenseForm = page.getByTestId("expense-form");

        // Cash on hand is the default, with no account to pick.
        await expect(expenseForm.getByTestId("expense-payment-method")).toContainText("Cash on hand (register)");
        await expect(expenseForm.getByTestId("paid-from-account")).toHaveCount(0);

        await expenseForm.getByTestId("expense-payment-method").click();
        await pickOption(page, "Bank transfer");
        if ((await pageProps(page)).options.accounts.some((a) => a.type !== "ewallet")) {
            await expect(expenseForm.getByTestId("paid-from-account")).toBeVisible();
        }
        await expenseForm.getByTestId("expense-payment-method").click();
        await pickOption(page, "Cash on hand");
        await expect(expenseForm.getByTestId("paid-from-account")).toHaveCount(0);
    });
});
