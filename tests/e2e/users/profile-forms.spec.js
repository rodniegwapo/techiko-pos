import { test, expect } from "../support/fixtures.js";

/**
 * The Profile page's Save buttons really send their form: profile information saves, and a wrong
 * current password on Update Password comes back as an error (the password itself is not changed,
 * so other tests can still sign in with it).
 */
test.describe("Profile forms", () => {
    test.use({ account: "wallet-supervisor" });

    test("Save on profile information sends the form", async ({ page }) => {
        await page.goto("/profile");
        const save = page.getByTestId("profile-info-save");
        const sent = page.waitForResponse((r) => r.request().method() === "PATCH" && new URL(r.url()).pathname === "/profile");
        await save.click();
        expect((await sent).status()).toBeLessThan(400);
        await expect(save.locator("xpath=..").getByText("Saved.")).toBeVisible();
    });

    test("Confirm on Confirm Password sends the form and shows a wrong password", async ({ page }) => {
        await page.goto("/confirm-password");
        await page.locator("#password").fill("not-my-password");

        const sent = page.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname === "/confirm-password");
        await page.getByTestId("confirm-password-submit").click();
        await sent;
        await expect(page.getByText(/password is incorrect|do not match|provided password/i)).toBeVisible();
    });

    test("Save on Update Password sends the form and shows a wrong current password", async ({ page }) => {
        await page.goto("/profile");
        await page.locator("#current_password").fill("not-my-password");
        await page.locator("#password").fill("new-password-123");
        await page.locator("#password_confirmation").fill("new-password-123");

        const sent = page.waitForResponse((r) => r.request().method() === "PUT" && new URL(r.url()).pathname === "/password");
        await page.getByTestId("profile-password-save").click();
        await sent;
        await expect(page.getByText(/password is incorrect/i)).toBeVisible();
        await expect(page.locator("#current_password")).toHaveValue("");
    });
});
