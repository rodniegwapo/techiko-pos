import { test, expect } from "../support/fixtures.js";
import { E2E } from "../support/users.js";
import { SalesPage } from "../support/sales.js";

/**
 * Timeclock: on the shared till, a person types their own PIN to clock in, and again to clock
 * out. The manager then sees the shift on Timesheets.
 *
 * Uses the manager's seeded PIN. Each punch flips them in or out, so the tests read which way the
 * first punch went instead of assuming they start off the clock.
 */
test.describe.configure({ mode: "serial" });

async function punch(page, pin) {
    await page.getByTestId("timeclock-open").first().click();
    const dialog = page.getByRole("dialog", { name: "Clock in / out" });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId("timeclock-pin").fill(pin);
    await dialog.getByTestId("timeclock-submit").click();
    const result = dialog.getByTestId("timeclock-result");
    await expect(result).toBeVisible();
    const text = await result.innerText();
    await dialog.getByRole("button", { name: "Done" }).click();
    await expect(dialog).toBeHidden();
    return text;
}

test.describe("Clock in / out on the POS", () => {
    test.use({ account: "worker-cashier" });

    test("a PIN clocks the person in, and the same PIN clocks them out", async ({ page }) => {
        await SalesPage.open(page, { layout: "classic" });

        const first = await punch(page, E2E.pins.manager);
        const second = await punch(page, E2E.pins.manager);

        const wentIn = /clocked in at/.test(first);
        expect(wentIn ? second : first).toMatch(/clocked out at/);
        expect(wentIn ? first : second).toMatch(/clocked in at/);
        if (wentIn) expect(second).toMatch(/today/);
    });

    test("a wrong PIN is refused and nothing is recorded", async ({ page }) => {
        await SalesPage.open(page, { layout: "classic" });

        await page.getByTestId("timeclock-open").first().click();
        const dialog = page.getByRole("dialog", { name: "Clock in / out" });
        await dialog.getByRole("button", { name: "9" }).click();
        await dialog.getByRole("button", { name: "8" }).click();
        await dialog.getByRole("button", { name: "7" }).click();
        await dialog.getByRole("button", { name: "6" }).click();
        await expect(dialog.getByTestId("timeclock-pin")).toHaveValue("9876");
        await dialog.getByTestId("timeclock-submit").click();

        await expect(dialog.getByTestId("timeclock-error")).toHaveText("That PIN is not right.");
        await expect(dialog.getByTestId("timeclock-pin")).toHaveValue("");
    });
});

test.describe("Timesheets", () => {
    test.use({ account: "admin" });

    test("shows the shift with its hours and a manager can fix it with a reason", async ({ page }) => {
        await page.goto(`/domains/${E2E.domain}/timesheets`);
        const table = page.getByTestId("timesheets-table");
        await expect(table).toBeVisible();
        const row = table.locator("tr", { hasText: /Fix/ }).first();
        await row.getByRole("button", { name: "Fix" }).first().click();

        const modal = page.getByRole("dialog").filter({ hasText: "Why the change" });
        await expect(modal).toBeVisible();
        await modal.getByRole("button", { name: "Save" }).click();
        // A reason is required.
        await expect(modal.getByText(/reason field is required/i)).toBeVisible();

        // The punches were seconds apart: clock-in goes an hour earlier, so the shift is 1h.
        const clockIn = modal.locator(".ant-picker input").first();
        const earlier = new Date(`${(await clockIn.inputValue()).replace(",", `, ${new Date().getFullYear()}`)}`);
        earlier.setHours(earlier.getHours() - 1);
        const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        const h = earlier.getHours();
        const typed = `${months[earlier.getMonth()]} ${earlier.getDate()}, ${h % 12 || 12}:${String(earlier.getMinutes()).padStart(2, "0")} ${h < 12 ? "AM" : "PM"}`;
        await clockIn.click();
        await clockIn.fill(typed);
        await clockIn.press("Enter");
        await expect(modal.getByText("That is")).toContainText("1h 00m");

        await modal.getByPlaceholder("e.g. Forgot to clock out, the till was down").fill("E2E check");
        await modal.getByRole("button", { name: "Save" }).click();
        await expect(modal).toBeHidden();
        await expect(table.getByText("Edited").first()).toBeVisible();
    });
});
