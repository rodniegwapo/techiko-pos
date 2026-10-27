import { test, expect, Tour, saveVideo } from "./tour.js";
import { DrawerPage, CashInOutPage, drawerUrl, numberInput, walletFixture, WALLET_DOMAIN } from "../support/wallet.js";
import { SalesPage } from "../support/sales.js";

/**
 * Cash and wallet, explained role by role. One story at worker 1's E2E wallet store, each chapter
 * recorded as its own video (tests/e2e/walkthrough/videos):
 *
 *   01-cashier     start a shift, sell for cash, end with a count, reopen with a reason
 *   02-supervisor  own drawer, who took in what, approve the cashier's count
 *   03-manager     cash in & out, end the store's day, reopen it, drawer history
 *   04-admin       any store: today's drawer, where the money is, counted money
 *
 * Run: npx playwright test -c playwright.walkthrough.config.js, then node tests/e2e/walkthrough/make-mp4.mjs <out dir>
 */
test.describe.configure({ mode: "serial" });

const peso = (text) => Number(String(text).replace(/[^0-9.-]/g, ""));
const countsTab = (page) => page.getByTestId("wallet-tab-counts");

test.describe("Cash and wallet walkthrough", () => {
    test.describe("01 Cashier", () => {
        test.use({ account: "wallet-cashier" });

        test("my shift, my drawer, my count", async ({ page }, testInfo) => {
            const tour = new Tour(page, "Cashier");
            const store = walletFixture(testInfo);
            const drawer = await DrawerPage.open(page);

            await tour.title("The cashier's shift", [
                "Each cashier has their own drawer and their own shift.",
                "Start with your change, sell, then count the drawer when you're done.",
            ]);
            await tour.say(`Signed in as ${store.cashierName}, on Today's drawer.`);
            await tour.point(page.getByTestId("my-shift"), 1500);
            await tour.say("\"My shift\" is my own drawer. Nothing is open yet, so I start my shift.");

            await tour.click(page.getByTestId("open-start-my-shift"));
            const start = drawer.dialog("Start my shift");
            await tour.type(numberInput(start, "my-shift-opening"), "500");
            await tour.say("I type the change I start with: ₱500.");
            await drawer.save("/wallet/shifts/start", () => tour.click(start.getByTestId("confirm-start-my-shift")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
            await tour.say("My shift is open. Expected is what should be in my drawer right now.");
            await tour.point(page.getByTestId("my-shift-figures"), 2000);

            // A cash sale.
            const sales = await SalesPage.open(page);
            await tour.say("Now I sell. A customer buys two items, ₱200, and pays cash.");
            await sales.addProduct(`E2E Product A W1`);
            await sales.addProduct(`E2E Product A W1`);
            await tour.point(sales.proceedButton, 1200);
            await sales.pay({ amountReceived: 500 });
            await tour.say("Paid in cash. It goes into my drawer, on my shift.");

            await DrawerPage.open(page);
            await tour.say("Back on Today's drawer: Expected is now ₱700. That's ₱500 opening plus ₱200 cash sales.");
            await tour.point(page.getByTestId("my-shift-expected"), 2200);

            // End with a count that is off.
            await tour.say("Time to go home. I end my shift and count the cash in my drawer.");
            await tour.click(page.getByTestId("open-end-my-shift"));
            const end = drawer.dialog("End my shift");
            await tour.type(numberInput(end, "end-shift-counted"), "680");
            await tour.say("I count ₱680. No manager's PIN is needed.");
            await drawer.save("/wallet/shifts/", () => tour.click(end.getByTestId("confirm-end-shift")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
            await tour.say("My count is off from Expected, so it goes to a manager to review. Nobody is stopped from selling.", 3200);
            await tour.say("As a cashier I only see what should be there, not whether I'm short or over.", 3000);

            // Reopen with a reason, count again.
            await tour.say("Wait: I find ₱10 in the coin tray. I reopen my shift.");
            await tour.click(page.getByTestId("reopen-my-shift"));
            await tour.type(page.getByTestId("reopen-reason"), "Found ₱10 in the coin tray");
            await tour.say("Reopening always asks why. The reason is kept in the shift's history.");
            await drawer.save("/reopen", () => tour.click(page.getByTestId("reopen-confirm")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");

            await tour.click(page.getByTestId("open-end-my-shift"));
            const again = drawer.dialog("End my shift");
            await tour.type(numberInput(again, "end-shift-counted"), "690");
            await tour.say("I count again: ₱690. Every count is kept; the first one isn't replaced.");
            await drawer.save("/wallet/shifts/", () => tour.click(again.getByTestId("confirm-end-shift")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Needs review");
            await tour.say("Still ₱10 short, so a supervisor or manager will review it. My part is done.", 3200);

            await saveVideo(page, "01-cashier", tour);
        });
    });

    test.describe("02 Supervisor", () => {
        test.use({ account: "wallet-supervisor" });

        test("my own drawer, who has what, approving a count", async ({ page }, testInfo) => {
            const tour = new Tour(page, "Supervisor");
            const store = walletFixture(testInfo);
            const drawer = await DrawerPage.open(page);

            await tour.title("The supervisor", [
                "Runs their own drawer like any cashier,",
                "sees who took in what, and reviews the cashiers' counts.",
            ]);
            await tour.say("The store's Expected counts every drawer at the store.");
            await tour.point(page.getByTestId("drawer-expected"), 1800);

            await tour.say("I start my own shift with ₱300 in my own drawer.");
            await tour.click(page.getByTestId("open-start-my-shift"));
            const start = drawer.dialog("Start my shift");
            await tour.type(numberInput(start, "my-shift-opening"), "300");
            await drawer.save("/wallet/shifts/start", () => tour.click(start.getByTestId("confirm-start-my-shift")));
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
            await tour.say("Because each cashier has their own drawer, the store's Expected went up by my ₱300.", 3200);
            await tour.point(page.getByTestId("drawer-expected"), 1800);

            // Who took in what.
            await tour.say("View details shows each person's money, and where it went.");
            await tour.click(page.getByTestId("open-money-by-user"));
            await expect(page.getByTestId("money-by-user")).toContainText(store.cashierName);
            await tour.say(`${store.cashierName}: the cash sale and the shift with its count. Mine: my open drawer.`, 3500);
            await page.keyboard.press("Escape");

            // Counted money: review the cashier's count.
            await tour.say("Counted money lists the counts that need a review.");
            await tour.click(countsTab(page));
            await expect(page.getByTestId("counts-table")).toBeVisible();
            const view = page.locator("[data-testid^=count-view-]").first();
            await tour.click(view);
            await expect(page.getByTestId("count-detail")).toBeVisible();
            await tour.say("The details show both counts, ₱680 then ₱690, and why the shift was reopened.", 3800);
            await page.keyboard.press("Escape");

            await tour.say("I recounted with the cashier: ₱10 short is right. I approve it with a note.");
            await tour.click(page.locator("[data-testid^=count-approve-]").first());
            await tour.type(page.getByTestId("approve-note"), "Recounted with the cashier, ₱10 short");
            const approved = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/approve"));
            await tour.click(page.getByTestId("approve-confirm"));
            expect((await approved).status()).toBeLessThan(400);
            await tour.say("Approved. An approved count is final; a later fix is a correction in Cash in & out.", 3200);

            // End my own shift.
            await DrawerPage.open(page);
            await tour.say("At the end I count my own drawer too. My count isn't approved by me but by someone else.");
            await tour.click(page.getByTestId("open-end-my-shift"));
            const end = drawer.dialog("End my shift");
            await tour.type(numberInput(end, "end-shift-counted"), "300");
            await drawer.save("/wallet/shifts/", () => tour.click(end.getByTestId("confirm-end-shift")));
            await tour.say("₱300, exactly as expected: my shift is ended with nothing to review.", 3000);

            await saveVideo(page, "02-supervisor", tour);
        });
    });

    test.describe("03 Manager", () => {
        test.use({ account: "wallet-manager" });

        test("cash in & out, ending and reopening the store's day", async ({ page }) => {
            const tour = new Tour(page, "Manager");

            const cash = await CashInOutPage.open(page);
            await tour.title("The manager", [
                "Records cash that moves outside of sales,",
                "and ends the store's day once every cashier has ended their shift.",
            ]);
            await tour.say("Cash in & out lists every line that moved the drawer's cash.");
            await tour.say("I put ₱1,000 of change from the safe into the drawer.");
            const dialog = await cash.openDialog("in");
            await tour.click(dialog.locator(".ant-radio-button-wrapper").filter({ hasText: "Added float" }));
            await tour.type(numberInput(dialog, "cash-amount"), "1000");
            await tour.type(dialog.getByTestId("cash-notes"), "Change from the safe");
            await tour.point(dialog.getByRole("button", { name: "Save" }), 800);
            await cash.save(dialog);
            await tour.say("Saved. The line shows who added it and when.", 2400);

            // Today's drawer: the store's day.
            const drawer = await DrawerPage.open(page);
            await tour.say("Today's drawer adds it all up for the store: openings, cash sales, cash in, cash out.", 3200);
            await tour.point(page.getByTestId("drawer-steps"), 2600);
            await tour.point(page.getByTestId("drawer-expected"), 1800);
            const expected = peso(await page.getByTestId("drawer-expected").innerText());

            await tour.say("Every cashier has ended their shift, so I can end the store's day.");
            await tour.click(page.getByTestId("open-end-shift"));
            const end = drawer.dialog("End the store's day");
            await tour.type(numberInput(end, "end-shift-counted"), expected);
            await tour.say(`I count all the cash: ₱${expected.toLocaleString("en-PH")}, matching Expected.`);
            await tour.click(end.getByTestId("end-shift-keep"));
            await tour.say("I leave it in the drawer as tomorrow's opening (or I could take the cash out).", 3000);
            await drawer.save("/cash-ledger/close-shift", () => tour.click(end.getByTestId("confirm-end-shift")));
            await expect(page.getByTestId("shift-closed")).toBeVisible();
            await tour.say("The day is ended. Nothing more can be added to it unless it's reopened.", 3000);

            // Reopen the store's day.
            await tour.say("A customer comes back to pay. I reopen the day, with a reason.");
            await tour.click(page.getByTestId("reopen-shift"));
            await tour.type(page.getByTestId("reopen-reason"), "A late customer paid in cash");
            await drawer.save("/cash-ledger/reopen-shift", () => tour.click(page.getByTestId("reopen-confirm")));
            await expect(page.getByTestId("shift-open")).toBeVisible();
            await tour.say("Reopened. The day carries on from the cash that stayed, and the reopen is logged.", 3200);
            await tour.point(page.getByTestId("last-reopen"), 2000);
            await tour.say("Ending it again needs a fresh count, so an old count can never be reused by mistake.", 3200);

            // History and counts.
            await tour.click(page.getByTestId("wallet-tab-history"));
            await tour.say("Drawer history keeps every count, every opening change and every reopen.", 3500);
            await tour.click(countsTab(page));
            await page.getByTestId("counts-filter-approved").click();
            await tour.say("Counted money: the cashier's count, approved by the supervisor, with the note.", 3500);

            await saveVideo(page, "03-manager", tour);
        });
    });

    test.describe("04 Admin", () => {
        test.use({ account: "admin" });

        test("every store's money", async ({ page }, testInfo) => {
            const tour = new Tour(page, "Admin");
            const store = walletFixture(testInfo);
            const query = `location_id=${store.locationId}`;

            await page.goto(`${drawerUrl}?${query}`);
            await expect(page.getByTestId("drawer-expected")).toBeVisible();
            await tour.title("The admin / owner", [
                "Sees every store: its drawer, who took in what,",
                "where the money is, and every count.",
            ]);
            await tour.say(`I can open any store. This is ${store.locationName}.`);
            await tour.point(page.getByTestId("drawer-steps"), 2200);

            await tour.click(page.getByTestId("open-money-by-user"));
            await expect(page.getByTestId("money-by-user")).toBeVisible();
            await tour.say("Who took in what today, person by person, and where it went.", 3200);
            await page.keyboard.press("Escape");

            await tour.click(page.getByTestId("wallet-tab-balances"));
            await tour.say("Where money is: the drawer's cash and each payment channel, like card terminals.", 3500);

            await page.goto(`/domains/${WALLET_DOMAIN}/wallet/shifts?${query}`);
            await expect(page.getByTestId("counts-filter")).toBeVisible();
            await page.getByTestId("counts-filter-approved").click();
            await tour.say("Counted money at this store: each count, who counted it and who approved it.", 3500);
            await tour.say("That's cash and wallet: every drawer counted, every change explained.", 3000);

            await saveVideo(page, "04-admin", tour);
        });
    });
});
