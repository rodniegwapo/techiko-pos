import { test, expect } from "../support/fixtures.js";
import { USERS } from "../support/users.js";

const DOMAIN = USERS.admin.domain;
const url = (path) => `/domains/${DOMAIN}${path}`;

// Every page title has a "?": what the page is for, in English and Tagalog.
test.describe("Page help as admin", () => {
    test.use({ account: "admin" });

    for (const [path, english] of [
        ["/wallet/drawer", "How much cash should be in the drawer today"],
        ["/products", "The items this store sells"],
        ["/expenses", "Money the business spends"],
    ]) {
        test(`${path} explains itself in English and Tagalog`, async ({ page }) => {
            await page.goto(url(path));

            await page.getByTestId("page-help").first().click();

            const help = page.getByTestId("page-help-content");
            await expect(help.getByTestId("page-help-en")).toContainText(english);
            await expect(help.getByTestId("page-help-tl")).not.toBeEmpty();
        });
    }

    test("a how-to link asks the assistant, when it is on", async ({ page }) => {
        await page.goto(url("/wallet/drawer"));
        await page.getByTestId("page-help").first().click();

        const guide = page.getByTestId("page-help-guide").first();
        test.skip(!(await guide.isVisible()), "the AI assistant is off for this organization");

        const title = await guide.innerText();
        await guide.click();

        await expect(page.getByText(`How do I: ${title}?`).first()).toBeVisible();
    });
});

test.describe("Page help as cashier", () => {
    test.use({ account: "cashier" });

    test("the sales screen has help too", async ({ page }) => {
        await page.goto(url("/sales"));

        await page.getByTestId("page-help").first().click();

        await expect(page.getByTestId("page-help-en")).toContainText("The cash register");
        await expect(page.getByTestId("page-help-tl")).toContainText("kaha");
    });
});
