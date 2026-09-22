import { test, expect } from "./support/fixtures.js";

test.describe("Login page", () => {
    test("renders the sign-in form", async ({ page }) => {
        await page.goto("/login");

        await expect(page).toHaveTitle(/Log in/);
        await expect(page.getByRole("heading", { name: "Welcome Back" })).toBeVisible();
        await expect(page.getByLabel("Email Address")).toBeVisible();
        await expect(page.getByLabel("Password", { exact: true })).toBeVisible();
    });

    test("shows an error for invalid credentials", async ({ page }) => {
        await page.goto("/login");
        await page.getByLabel("Email Address").fill("nobody@example.com");
        await page.getByLabel("Password", { exact: true }).fill("wrong-password");
        await page.getByRole("button", { name: "Sign In" }).click();

        await expect(page.getByText("These credentials do not match our records.")).toBeVisible();
        await expect(page).toHaveURL(/\/login/);
    });
});
