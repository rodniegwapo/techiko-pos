import { test, expect } from "../support/fixtures.js";
import { USERS } from "../support/users.js";

const DOMAIN = USERS.admin.domain;
const url = (path) => `/domains/${DOMAIN}${path}`;

async function pickOption(page, label) {
    await page.locator(".ant-select-dropdown:visible .ant-select-item-option", { hasText: label }).first().click();
}
const input = (scope, testId) => scope.getByTestId(testId).locator("input").or(scope.getByTestId(testId)).first();

// Money movement's "All money" view lists money recorded outside the drawer too, read-only.
test.describe("All money on Money movement as admin", () => {
    test.use({ account: "admin" });

    test("a bank expense shows under All money with its account, and the drawer view stays as it was", async ({ page }) => {
        const stamp = Date.now();
        const account = `E2E Timeline Bank ${stamp}`;
        const description = `E2E timeline expense ${stamp}`;

        await page.goto(url("/finance/assets-and-loans"));
        await page.getByTestId("add-account").click();
        await input(page.getByTestId("account-form"), "account-name").fill(account);
        await page.locator(".ant-modal:visible", { hasText: "Add bank or e-wallet account" }).getByRole("button", { name: "Save" }).click();
        await expect(page.locator(".ant-message")).toContainText("Account saved");

        await page.goto(url("/expenses"));
        await page.getByTestId("expenses-create").click();
        const form = page.getByTestId("expense-form");
        await input(form, "expense-amount").fill("450");
        await form.getByTestId("expense-description").fill(description);
        await form.getByTestId("expense-category").click();
        await page.keyboard.type("Utilities");
        await page.keyboard.press("Enter");
        await form.getByTestId("expense-payment-method").click();
        await pickOption(page, "Bank transfer");
        await form.getByTestId("paid-from-account").click();
        await pickOption(page, account);
        await page.getByTestId("expense-submit").click();
        await expect(page.getByTestId("expenses-table")).toContainText(description);

        await page.goto(url("/wallet/money-movement"));
        await expect(page.locator("#cash-ledger")).toBeVisible();
        await page.getByTestId("money-view").getByText("All money").click();

        const table = page.getByTestId("all-money-table");
        const row = table.locator("tr", { hasText: description });
        await expect(row).toContainText(account);
        await expect(row).toContainText("₱450.00");
        await expect(row).toContainText("Expense");
        await expect(page.getByTestId("all-money-totals")).toContainText("Money out");

        // Only that account.
        await page.getByTestId("all-money-where").click();
        await page.keyboard.type(account);
        await pickOption(page, account);
        await page.getByTestId("all-money-apply").click();
        await expect(page).toHaveURL(/where=account%3A\d+/);
        await expect(table.locator("tbody tr.ant-table-row")).toHaveCount(1);
        await expect(table).toContainText(description);

        // The row opens the page it came from.
        await table.getByRole("link", { name: description }).click();
        await expect(page).toHaveURL(/\/expenses/);

        // Back on the drawer view the ledger is unchanged and editable as before.
        await page.goto(url("/wallet/money-movement?view=all"));
        await page.getByTestId("money-view").getByText("Drawer & channels").click();
        await expect(page.locator("#cash-ledger")).toBeVisible();
        await expect(page.getByTestId("all-money")).toHaveCount(0);
    });
});

test.describe("All money on Money movement as cashier", () => {
    test.use({ account: "cashier" });

    test("has no All money switch", async ({ page }) => {
        await page.goto(url("/wallet/money-movement?view=all"));
        await expect(page.getByTestId("money-view")).toHaveCount(0);
        await expect(page.getByTestId("all-money")).toHaveCount(0);
    });
});
