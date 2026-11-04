import { test, expect } from "../support/fixtures.js";
import { workerNumber } from "../support/users.js";
import { DrawerPage, numberInput } from "../support/wallet.js";

/**
 * Opening your drawer can clock you in, and closing it can clock you out, with your own PIN. The
 * cashier first sets their own PIN on Profile (each worker its own, so PINs stay unique).
 */
test.describe.configure({ mode: "serial" });

test.describe("Clock in / out with the drawer", () => {
    test.use({ account: "wallet-cashier" });

    test("set my PIN, open my drawer and clock in, then close it and clock out", async ({ page }, testInfo) => {
        const n = workerNumber(testInfo.parallelIndex);
        const myPin = `8${n}${n}${n}`;

        // Change my PIN on Profile.
        await page.goto("/profile");
        const pinForm = page.getByTestId("profile-pin");
        await expect(pinForm).toBeVisible();
        await pinForm.getByTestId("profile-pin-password").fill("e2e-password");
        await pinForm.getByTestId("profile-pin-new").fill(myPin);
        await pinForm.getByTestId("profile-pin-confirm").fill(myPin);
        await pinForm.getByTestId("profile-pin-save").click();
        await expect(pinForm.getByTestId("profile-pin-saved")).toBeVisible();

        const drawer = await DrawerPage.open(page);

        // Left open by an earlier run: close it, clocking out too if they're on the clock.
        if (await page.getByTestId("open-end-my-shift").isVisible()) {
            await page.getByTestId("open-end-my-shift").click();
            const end = drawer.dialog("Close my drawer");
            await numberInput(end, "end-shift-counted").fill("0");
            if (await end.getByTestId("clock-along-toggle").isVisible()) {
                await end.getByTestId("clock-along-toggle").check();
                await end.getByTestId("clock-along-pin").fill(myPin);
            }
            await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
        }

        // Open my drawer: "Clock me in too" is ticked, and the button waits for the PIN.
        await page.getByTestId("open-start-my-shift").click();
        const start = drawer.dialog("Open my drawer");
        await expect(start.getByTestId("clock-along-toggle")).toBeChecked();
        await expect(start.getByTestId("confirm-start-my-shift")).toBeDisabled();

        // A wrong PIN: nothing opens, the error shows under the PIN.
        await numberInput(start, "my-shift-opening").fill("300");
        await start.getByTestId("clock-along-pin").fill("0000");
        const refused = await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
        expect(refused.status()).toBe(422);
        await expect(start.getByTestId("clock-along-error")).toHaveText("That PIN is not right.");
        await expect(start).toBeVisible();

        await start.getByTestId("clock-along-pin").fill(myPin);
        const started = await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click());
        expect(started.status()).toBeLessThan(400);
        await expect(page.getByText("Drawer opened. You're clocked in.")).toBeVisible();
        await expect(page.getByTestId("my-shift-status")).toHaveText("Open");

        // Close my drawer: "Clock me out too" is offered, unticked.
        await page.getByTestId("open-end-my-shift").click();
        const end = drawer.dialog("Close my drawer");
        await numberInput(end, "end-shift-counted").fill("300");
        const toggle = end.getByTestId("clock-along-toggle");
        await expect(toggle).not.toBeChecked();
        await toggle.check();
        await end.getByTestId("clock-along-pin").fill(myPin);
        const ended = await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click());
        expect(ended.status()).toBeLessThan(400);
        await expect(page.getByText("Drawer closed. You're clocked out.")).toBeVisible();

        // Off the clock now: opening again offers to clock in again.
        await page.getByTestId("open-start-my-shift").click();
        await expect(drawer.dialog("Open my drawer").getByTestId("clock-along-toggle")).toBeChecked();
    });
});
