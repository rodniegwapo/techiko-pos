import { mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { test, expect, Tour } from "./tour.js";
import { DrawerPage, CashInOutPage, numberInput, walletFixture, WALLET_DOMAIN } from "../support/wallet.js";
import { SalesPage } from "../support/sales.js";
import { pickSelectOption } from "../support/antd.js";

/**
 * Screen footage for the Cash & wallet explainer (video/wallet-management): one clean clip per
 * scene, no captions or voice (the video adds Groq's voiceover and its own captions). The roles
 * run at worker 1's E2E wallet store; the money between stores at the demo stores, Makati (1) and
 * SM Mall (2), with ₱100 lines noted "Video demo".
 *
 *   npx playwright test -c playwright.walkthrough.config.js wallet-video
 */
test.describe.configure({ mode: "serial" });

const CLIPS = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..", "video", "wallet-management", "clips");
const MAKATI = 1;
const SM_MALL = 2;
const NOTE = "Video demo";
const url = (path) => `/domains/${WALLET_DOMAIN}${path}`;

/** Tour's ring, clicks and slow typing, without its captions and Windows voice. */
class Clip extends Tour {
    say(_text, hold = 1800) {
        return this.pause(hold);
    }

    title() {
        return this.pause(600);
    }

    /** Points at an element only when the page has it. */
    async pointIf(locator, hold = 1500) {
        if (await locator.count()) await this.point(locator.first(), hold);
    }

    /** Picks an Ant Design select's option, slowly enough to follow. */
    async pick(scope, testId, option) {
        await this.point(scope.getByTestId(testId), 500);
        const index = await scope.locator(".ant-select").evaluateAll(
            (els, id) => els.findIndex((el) => el.matches(`[data-testid="${id}"]`) || el.querySelector(`[data-testid="${id}"]`) || el.closest(`[data-testid="${id}"]`)),
            testId,
        );
        await pickSelectOption(this.page, scope, index, option);
    }
}

async function saveClip(page, name) {
    mkdirSync(CLIPS, { recursive: true });
    await page.waitForTimeout(800);
    const video = page.video();
    await page.close();
    await video?.saveAs(join(CLIPS, `${name}.webm`));
}

/** Waits for the POST to `path` and the redirects after it. */
async function saved(page, path, act) {
    const res = page.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname.includes(path));
    await act();
    expect((await res).status()).toBeLessThan(400);
    await page.waitForLoadState("networkidle");
}

test.describe("Cash & wallet video", () => {
    test.describe("scene-3 Cashier", () => {
        test.use({ account: "wallet-cashier" });

        test("start, sell, end and count", async ({ page }) => {
            const clip = new Clip(page, "Cashier");
            const drawer = await DrawerPage.open(page);

            await clip.point(page.getByTestId("my-shift"), 1500);
            await clip.click(page.getByTestId("open-start-my-shift"));
            const start = drawer.dialog("Start my shift");
            await clip.type(numberInput(start, "my-shift-opening"), "500");
            await drawer.save("/wallet/shifts/start", () => clip.click(start.getByTestId("confirm-start-my-shift")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
            await clip.point(page.getByTestId("my-shift-figures"), 1800);

            const sales = await SalesPage.open(page);
            await sales.addProduct("E2E Product A W1");
            await sales.addProduct("E2E Product A W1");
            await clip.point(sales.proceedButton, 1000);
            await sales.pay({ amountReceived: 500 });
            await clip.say("", 1200);

            await DrawerPage.open(page);
            await clip.point(page.getByTestId("my-shift-expected"), 1800);
            await clip.click(page.getByTestId("open-end-my-shift"));
            const end = drawer.dialog("End my shift");
            await clip.type(numberInput(end, "end-shift-counted"), "690");
            await drawer.save("/wallet/shifts/", () => clip.click(end.getByTestId("confirm-end-shift")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
            await clip.point(page.getByTestId("my-shift-status"), 2000);

            await saveClip(page, "scene-3");
        });
    });

    test.describe("scene-4 Supervisor", () => {
        test.use({ account: "wallet-supervisor" });

        test("the store's drawer and reviewing a count", async ({ page }) => {
            const clip = new Clip(page, "Supervisor");
            await DrawerPage.open(page);

            await clip.point(page.getByTestId("drawer-expected"), 1800);
            await clip.pointIf(page.getByTestId("drawer-steps"), 1800);
            await clip.pointIf(page.getByTestId("spot-check-my-drawer"), 1500);

            await clip.click(page.getByTestId("wallet-tab-counts"));
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await clip.pointIf(page.locator("[data-testid^=count-reopen-]"), 1800);
            await clip.click(page.locator("[data-testid^=count-approve-]").first());
            await clip.type(page.getByTestId("approve-note"), "Recounted with the cashier");
            await saved(page, "/approve", () => clip.click(page.getByTestId("approve-confirm")));
            await clip.say("", 1500);

            await saveClip(page, "scene-4");
        });
    });

    test.describe("scene-5 Manager", () => {
        test.use({ account: "wallet-manager" });

        test("cash in & out, and ending the store's day", async ({ page }) => {
            const clip = new Clip(page, "Manager");

            // Ending the day first: every shift at the store has ended by now.
            const drawer = await DrawerPage.open(page);
            await clip.point(page.getByTestId("drawer-expected"), 1500);
            const expected = Number((await page.getByTestId("drawer-expected").innerText()).replace(/[^0-9.-]/g, ""));
            await clip.click(page.getByTestId("open-end-shift"));
            const end = drawer.dialog("End the store's day");
            await clip.type(numberInput(end, "end-shift-counted"), expected);
            await clip.point(end.getByTestId("end-shift-cashout"), 1500);
            await clip.point(end.getByTestId("end-shift-keep"), 1200);
            await clip.click(end.getByTestId("end-shift-keep"));
            await drawer.save("/cash-ledger/close-shift", () => clip.click(end.getByTestId("confirm-end-shift")));
            await expect(page.getByTestId("shift-closed")).toBeVisible();
            await clip.say("", 1500);

            // Reopened so the day can take a cash-in line.
            await clip.click(page.getByTestId("reopen-shift"));
            await clip.type(page.getByTestId("reopen-reason"), NOTE);
            await drawer.save("/cash-ledger/reopen-shift", () => clip.click(page.getByTestId("reopen-confirm")));

            const cash = await CashInOutPage.open(page);
            const dialog = await cash.openDialog("in");
            await clip.point(dialog.getByTestId("cash-mode"), 1500);
            await clip.click(dialog.locator(".ant-radio-button-wrapper").filter({ hasText: "Added float" }));
            await clip.type(numberInput(dialog, "cash-amount"), "1000");
            await clip.type(dialog.getByTestId("cash-notes"), NOTE);
            await cash.save(dialog);
            await clip.say("", 1800);

            await saveClip(page, "scene-5");
        });
    });

    test.describe("Owner", () => {
        test.use({ account: "admin" });

        test("scene-6 turn on Can transfer money", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            for (const id of [MAKATI, SM_MALL]) {
                await page.goto(url(`/inventory/locations/${id}/edit`));
                const toggle = page.getByTestId("location-allows-money-transfer");
                await expect(toggle).toBeVisible();
                await clip.point(toggle, 1200);
                if ((await toggle.getAttribute("aria-checked")) !== "true") await toggle.click();
                await clip.pause(800);
                await clip.click(page.getByRole("button", { name: /Update Location/ }));
                const confirm = page.locator(".ant-modal-confirm");
                if (await confirm.isVisible().catch(() => false)) {
                    await saved(page, `/inventory/locations/${id}`, () => clip.click(confirm.getByRole("button", { name: "Save" })));
                }
                await clip.pause(1200);
            }
            await saveClip(page, "scene-6");
        });

        test("scene-7a transfer straight to another store", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            const cash = await CashInOutPage.open(page, { location_id: MAKATI });
            const dialog = await cash.openDialog("transfer");
            await clip.point(dialog.getByTestId("transfer-from"), 1000);
            await clip.pick(dialog, "transfer-to-store", "SM Mall");
            await clip.pick(dialog, "transfer-to", "Cash drawer");
            await clip.type(numberInput(dialog, "cash-amount"), "100");
            await clip.type(dialog.getByTestId("cash-notes"), NOTE);
            await clip.point(dialog.getByRole("button", { name: "Save" }), 800);
            await cash.save(dialog);
            await clip.pointIf(cash.row("SM Mall"), 2000);
            await saveClip(page, "scene-7a");
        });

        test("scene-7b owner draw into another store's drawer", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            const cash = await CashInOutPage.open(page, { location_id: MAKATI });
            const dialog = await cash.openDialog("out");
            await clip.click(dialog.locator(".ant-radio-button-wrapper").filter({ hasText: "Owner took cash" }));
            await clip.pick(dialog, "owner-to-store", "SM Mall");
            await clip.type(numberInput(dialog, "cash-amount"), "100");
            await clip.type(dialog.getByTestId("cash-notes"), NOTE);
            await clip.point(dialog.getByRole("button", { name: "Save" }), 800);
            await cash.save(dialog);
            await clip.pointIf(cash.row("SM Mall"), 2000);
            await saveClip(page, "scene-7b");
        });

        test("scene-7c from Cash on hand to a store", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            await page.goto(url("/wallet/cash-on-hand"));
            await expect(page.getByTestId("cash-on-hand-balance")).toBeVisible();
            await clip.point(page.getByTestId("cash-on-hand-balance"), 1800);
            await clip.pointIf(page.getByTestId("cash-on-hand-waiting"), 1500);
            await clip.click(page.getByTestId("cash-on-hand-to-store"));
            const modal = page.locator(".ant-modal").filter({ hasText: "Store" });
            await clip.pick(modal, "cash-on-hand-store", "SM Mall");
            await clip.point(modal.getByTestId("cash-on-hand-place"), 1000);
            await clip.type(numberInput(modal, "cash-on-hand-amount"), "100");
            await clip.type(modal.getByTestId("cash-on-hand-notes"), NOTE);
            await saved(page, "/wallet/cash-on-hand", () => clip.click(page.getByTestId("cash-on-hand-save")));
            await clip.pointIf(page.getByTestId("cash-on-hand-lines"), 2000);
            await saveClip(page, "scene-7c");
        });
    });
});
