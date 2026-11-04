import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { test, expect, Tour } from "./tour.js";
import { DrawerPage, CashInOutPage, numberInput, balancesUrl, WALLET_DOMAIN } from "../support/wallet.js";
import { SalesPage } from "../support/sales.js";
import { pickSelectOption } from "../support/antd.js";

/**
 * Screen footage for the Cash & wallet explainer (video/wallet-management): one clean clip per
 * scene of its script.md (a scene in parts a, b, c is joined in that order), no captions or voice:
 * the video adds Groq's voiceover and its own captions. Each clip has a .json saying where the
 * scene starts, so the login before it is cut. The roles run at worker 1's E2E wallet store; the
 * money between stores at the demo stores, Makati (1) and SM Mall (2), with ₱100 lines noted
 * "Video demo".
 *
 *   E2E_VIDEO_W=1600 E2E_VIDEO_H=900 npx playwright test -c playwright.walkthrough.config.js wallet-video
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

    /** Marks where the scene's footage starts: what came before (login, loading) is cut. */
    mark() {
        this.markedAt = Math.max(0, (Date.now() - (this.page.videoStartedAt ?? Date.now())) / 1000 - 0.3);
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

/** Saves the page's video as clips/<name>.webm, and clips/<name>.json with where the scene starts (s). */
async function saveClip(page, name, clip) {
    mkdirSync(CLIPS, { recursive: true });
    await page.waitForTimeout(800);
    const video = page.video();
    await page.close();
    await video?.saveAs(join(CLIPS, `${name}.webm`));
    writeFileSync(join(CLIPS, `${name}.json`), JSON.stringify({ trimStart: Number((clip?.markedAt ?? 0).toFixed(2)) }));
}

/** Waits for the save (POST, PUT or PATCH) to `path` and the redirects after it. */
async function saved(page, path, act) {
    const res = page.waitForResponse((r) => r.request().method() !== "GET" && new URL(r.url()).pathname.includes(path));
    await act();
    expect((await res).status()).toBeLessThan(400);
    await page.waitForLoadState("networkidle");
}

/** The cashier ends their own shift with `counted` in the tray. */
async function endMyShift(clip, drawer, counted) {
    const page = clip.page;
    await clip.point(page.getByTestId("my-shift-expected"), 1800);
    await clip.click(page.getByTestId("open-end-my-shift"));
    const end = drawer.dialog("Close my drawer");
    await clip.type(numberInput(end, "end-shift-counted"), String(counted));
    await drawer.save("/wallet/shifts/", () => clip.click(end.getByTestId("confirm-end-shift")));
    await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
    await clip.point(page.getByTestId("my-shift-status"), 2000);
}

test.describe("Cash & wallet video", () => {
    test.describe("scene-4 Cashier", () => {
        test.use({ account: "wallet-cashier" });

        test("start, sell, end and count", async ({ page }) => {
            const clip = new Clip(page, "Cashier");
            const drawer = await DrawerPage.open(page);
            clip.mark();

            await clip.point(page.getByTestId("my-shift"), 1500);
            await clip.click(page.getByTestId("open-start-my-shift"));
            const start = drawer.dialog("Open my drawer");
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
            // ₱10 short of Expected, so it waits for a review.
            await endMyShift(clip, drawer, 690);

            await saveClip(page, "scene-4", clip);
        });
    });

    test.describe("scene-5a Manager sends the count back", () => {
        test.use({ account: "wallet-manager" });

        test("send back for a recount", async ({ page }) => {
            const clip = new Clip(page, "Manager");
            await page.goto(url("/wallet/shifts"));
            await expect(page.getByTestId("counts-table")).toBeVisible();
            clip.mark();

            await clip.pointIf(page.getByTestId("counts-table"), 1500);
            const recount = page.locator("[data-testid^=count-reopen-]").first();
            await expect(recount).toBeVisible();
            await clip.click(recount);
            const dialog = page.getByRole("dialog").filter({ hasText: "Reopen for a recount" });
            await clip.type(dialog.getByTestId("reopen-reason"), "Count the coins again");
            await saved(page, "/reopen", () => clip.click(dialog.getByTestId("reopen-confirm")));
            await clip.say("", 1500);

            await saveClip(page, "scene-5a", clip);
        });
    });

    test.describe("scene-5b Cashier recounts", () => {
        test.use({ account: "wallet-cashier" });

        test("count again", async ({ page }) => {
            const clip = new Clip(page, "Cashier");
            const drawer = await DrawerPage.open(page);
            clip.mark();
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
            await clip.point(page.getByTestId("my-shift-status"), 1500);
            // Still ₱10 short: the supervisor approves it with a note next.
            await endMyShift(clip, drawer, 690);
            await saveClip(page, "scene-5b", clip);
        });
    });

    test.describe("scene-6 Supervisor", () => {
        test.use({ account: "wallet-supervisor" });

        test("the store's drawer and reviewing a count", async ({ page }) => {
            const clip = new Clip(page, "Supervisor");
            await DrawerPage.open(page);
            clip.mark();

            await clip.pointIf(page.getByTestId("summary-cash-in-drawers"), 1500);
            await clip.pointIf(page.getByTestId("summary-cash-sales"), 1300);
            await clip.pointIf(page.getByTestId("summary-digital"), 1300);
            await clip.pointIf(page.getByTestId("summary-discrepancy"), 1500);
            await clip.pointIf(page.getByTestId("store-drawer-line"), 1800);
            await clip.pointIf(page.getByTestId("drawer-expected"), 1500);
            await clip.pointIf(page.locator("table").filter({ hasText: "Short / Over" }), 2000);
            await clip.pointIf(page.getByTestId("required-actions"), 2000);

            await clip.click(page.getByTestId("wallet-tab-counts"));
            await expect(page.getByTestId("counts-table")).toBeVisible();
            await clip.pointIf(page.locator("[data-testid^=count-reopen-]"), 1500);
            await clip.click(page.locator("[data-testid^=count-approve-]").first());
            await clip.type(page.getByTestId("approve-note"), "Recounted with the cashier");
            await saved(page, "/approve", () => clip.click(page.getByTestId("approve-confirm")));
            await clip.say("", 1500);

            await saveClip(page, "scene-6", clip);
        });
    });

    test.describe("Manager", () => {
        test.use({ account: "wallet-manager" });

        test("scene-7 ending the store's day", async ({ page }) => {
            const clip = new Clip(page, "Manager");
            // Every shift at the store has ended by now.
            const drawer = await DrawerPage.open(page);
            clip.mark();
            await clip.pointIf(page.getByTestId("store-drawer-line"), 1500);
            await clip.pointIf(page.getByTestId("drawer-expected"), 1500);
            await clip.click(page.getByTestId("open-end-shift"));
            const end = drawer.dialog("End the store's day");
            // The dialog says "Expected: ₱1,190.00"; the count matches it.
            const expected = Number((await end.getByText(/^\s*Expected:/).first().innerText()).match(/[\d,]+\.\d{2}/)[0].replace(/,/g, ""));
            await clip.type(numberInput(end, "end-shift-counted"), expected);
            await clip.point(end.getByTestId("end-shift-cashout"), 2000);
            await clip.point(end.getByTestId("end-shift-keep"), 1800);
            await clip.click(end.getByTestId("end-shift-keep"));
            await drawer.save("/cash-ledger/close-shift", () => clip.click(end.getByTestId("confirm-end-shift")));
            await expect(page.getByTestId("shift-closed")).toBeVisible();
            await clip.point(page.getByTestId("shift-closed"), 1800);
            await clip.pointIf(page.getByTestId("reopen-shift"), 1500);

            await saveClip(page, "scene-7", clip);
        });

        test("scene-8 cash in & out", async ({ page }) => {
            const clip = new Clip(page, "Manager");
            // Reopened off camera (cut by the trim) so the day can take a cash-in line.
            const drawer = await DrawerPage.open(page);
            await page.getByTestId("reopen-shift").click();
            await page.getByTestId("reopen-reason").fill(NOTE);
            await drawer.save("/cash-ledger/reopen-shift", () => page.getByTestId("reopen-confirm").click());

            const cash = await CashInOutPage.open(page);
            clip.mark();
            await clip.pause(800);
            const dialog = await cash.openDialog("in");
            await clip.point(dialog.getByTestId("cash-mode"), 1500);
            await clip.click(dialog.locator(".ant-radio-button-wrapper").filter({ hasText: "Added float" }));
            await clip.type(numberInput(dialog, "cash-amount"), "1000");
            await clip.type(dialog.getByTestId("cash-notes"), NOTE);
            await clip.pause(1500);
            await cash.save(dialog);
            await clip.say("", 1500);

            // Cash out and Move, shown but not saved.
            const out = await cash.openDialog("out");
            await clip.pointIf(out.locator(".ant-radio-button-wrapper").filter({ hasText: "Owner took cash" }), 1500);
            const moveTab = out.getByTestId("move-money");
            if (await moveTab.count()) {
                await clip.click(moveTab);
                await clip.pause(1800);
            }
            await page.keyboard.press("Escape");
            await clip.pause(1500);

            await saveClip(page, "scene-8", clip);
        });
    });

    test.describe("Owner", () => {
        test.use({ account: "admin" });

        test("scene-9 where money is", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            await page.goto(balancesUrl);
            await expect(page.getByTestId("balances-tabs")).toBeVisible();
            clip.mark();
            await clip.pause(1000);
            await clip.pointIf(page.getByTestId("balance-totals"), 1800);
            for (const group of ["cash", "ewallet", "bank"]) {
                await clip.pointIf(page.getByTestId(`place-group-${group}`), 1500);
            }
            const stores = page.getByTestId("balances-tab-stores");
            if (await stores.count()) {
                await clip.click(stores);
                await clip.pointIf(page.getByTestId("all-stores-money"), 2000);
                await clip.pointIf(page.getByTestId("all-stores-cash-on-hand"), 1800);
            }
            await saveClip(page, "scene-9", clip);
        });

        test("scene-10 turn on Can transfer money", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            for (const [n, id] of [MAKATI, SM_MALL].entries()) {
                await page.goto(url(`/inventory/locations/${id}/edit`));
                const toggle = page.getByTestId("location-allows-money-transfer");
                await expect(toggle).toBeVisible();
                if (n === 0) clip.mark();
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
            await saveClip(page, "scene-10", clip);
        });

        test("scene-11a transfer straight to another store", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            const cash = await CashInOutPage.open(page, { location_id: MAKATI });
            clip.mark();
            const dialog = await cash.openDialog("transfer");
            await clip.point(dialog.getByTestId("transfer-from"), 1000);
            await clip.pick(dialog, "transfer-to-store", "SM Mall");
            await clip.pick(dialog, "transfer-to", "Cash drawer");
            await clip.type(numberInput(dialog, "cash-amount"), "100");
            await clip.type(dialog.getByTestId("cash-notes"), NOTE);
            await clip.point(dialog.getByRole("button", { name: "Save" }), 800);
            await cash.save(dialog);
            await clip.pause(1500);
            await saveClip(page, "scene-11a", clip);
        });

        test("scene-11b owner draw into another store's drawer", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            const cash = await CashInOutPage.open(page, { location_id: MAKATI });
            clip.mark();
            const dialog = await cash.openDialog("out");
            await clip.click(dialog.locator(".ant-radio-button-wrapper").filter({ hasText: "Owner took cash" }));
            await clip.pick(dialog, "owner-to-store", "SM Mall");
            await clip.type(numberInput(dialog, "cash-amount"), "100");
            await clip.type(dialog.getByTestId("cash-notes"), NOTE);
            await clip.point(dialog.getByRole("button", { name: "Save" }), 800);
            await cash.save(dialog);
            await clip.pause(1500);
            await saveClip(page, "scene-11b", clip);
        });

        test("scene-11c from Cash on hand to a store", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            await page.goto(url("/wallet/cash-on-hand"));
            await expect(page.getByTestId("cash-on-hand-balance")).toBeVisible();
            clip.mark();
            await clip.point(page.getByTestId("cash-on-hand-balance"), 1200);
            await clip.click(page.getByTestId("cash-on-hand-to-store"));
            const modal = page.locator(".ant-modal").filter({ hasText: "Move to a store" });
            await clip.pick(modal, "cash-on-hand-store", "SM Mall");
            await clip.point(modal.getByTestId("cash-on-hand-place"), 1000);
            await clip.type(numberInput(modal, "cash-on-hand-amount"), "100");
            await clip.type(modal.getByTestId("cash-on-hand-notes"), NOTE);
            await saved(page, "/wallet/cash-on-hand", () => clip.click(page.getByTestId("cash-on-hand-save")));
            await clip.pause(1500);
            await saveClip(page, "scene-11c", clip);
        });

        test("scene-12 Cash on hand in depth", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            await page.goto(url("/wallet/cash-on-hand"));
            await expect(page.getByTestId("cash-on-hand-balance")).toBeVisible();
            clip.mark();
            await clip.point(page.getByTestId("cash-on-hand-balance"), 1800);
            await clip.pointIf(page.getByTestId("cash-on-hand-waiting"), 1800);

            // Collect the shift the supervisor approved, when it is waiting here.
            const collect = page.locator("[data-testid^=collect-shift-]").first();
            if (await collect.count()) {
                await clip.click(collect);
                const modal = page.locator(".ant-modal").filter({ hasText: "Collect into Cash on hand" });
                await clip.point(modal.getByTestId("collect-amount"), 1500);
                await saved(page, "/cash-on-hand/shifts/", () => clip.click(modal.getByTestId("collect-confirm")));
                await clip.pause(1200);
            }
            await clip.pointIf(page.getByTestId("cash-on-hand-collected-today"), 1500);
            await clip.pointIf(page.getByTestId("cash-on-hand-moved-today"), 1500);
            await clip.pointIf(page.getByTestId("cash-on-hand-lines"), 1800);

            // Owner used it: opened to show, not saved.
            const ownerUse = page.getByTestId("cash-on-hand-owner-use");
            if (await ownerUse.isEnabled()) {
                await clip.click(ownerUse);
                await clip.pause(2200);
                await page.keyboard.press("Escape");
            } else {
                await clip.point(ownerUse, 1800);
            }
            await clip.pause(1200);
            await saveClip(page, "scene-12", clip);
        });

        test("scene-13 cash flow", async ({ page }) => {
            const clip = new Clip(page, "Admin");
            await page.goto(url("/finance/cash-flow?period=month"));
            await expect(page.getByTestId("cash-flow-statement")).toBeVisible();
            clip.mark();
            await clip.pause(1000);
            await clip.pointIf(page.getByTestId("cash-flow-summary"), 2200);
            await clip.pointIf(page.getByTestId("cash-flow-statement"), 2500);
            await page.mouse.wheel(0, 500);
            await clip.pause(1200);
            await clip.pointIf(page.getByTestId("account-balances"), 2200);
            await clip.pointIf(page.getByTestId("profit-vs-cash"), 2500);
            await clip.pause(1200);
            await saveClip(page, "scene-13", clip);
        });
    });
});
