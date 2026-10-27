import { execSync } from "node:child_process";
import { test, expect, apiJson } from "../support/fixtures.js";
import { responseProps } from "../support/inertia.js";
import {
    DrawerPage,
    drawerUrl,
    ledgerUrl,
    numberInput,
    dateWindow,
    walletFixture,
    walletState,
    ledgerState,
    postLedger,
    postOpening,
    postCounted,
    postEndShift,
    postReopen,
    expectSaved,
    todayYmd,
    WALLET_DOMAIN,
} from "../support/wallet.js";

/**
 * Drawer money when shifts and store days are ended and reopened again and again, role by role.
 *
 * - A cashier's own shift (register_shifts): every end adds a count, every reopen a log row, and
 *   Expected never moves on its own.
 * - The store's day (wallet_cash_reconciliations): closing with "Take the cash out" or "Leave it as
 *   the next shift's opening", reopening, and closing again must not lose or double the cash.
 * - Several people on shift at once: the store's Expected and the shifts' Expecteds tell the same story.
 *
 * Store-day tests use past dates from dateWindow(); shift tests are today's (shifts only start
 * today), so each one ends the shifts it opened: an open shift stops "End the day" at the store.
 */
const shiftsUrl = (path = "") => `/domains/${WALLET_DOMAIN}/wallet/shifts${path}`;

/** Admins and the super user aren't tied to the wallet store, so they name it. */
const OFF_STORE = new Set(["admin", "super"]);
const storeQuery = (account, testInfo) => (OFF_STORE.has(account) ? { location_id: String(walletFixture(testInfo).locationId) } : {});

async function drawerProps(api, query = {}) {
    const params = new URLSearchParams(query);
    const res = await api.get(`${drawerUrl}${params.size ? `?${params}` : ""}`);
    expect(res.status(), "today's drawer").toBe(200);
    return responseProps(res);
}

/** Ends the user's open shift, if any, with a count that matches. */
async function endOpenShift(api, query = {}) {
    const mine = (await drawerProps(api, query)).myShift;
    if (mine?.status !== "open") return;
    const res = await apiJson(api, "POST", shiftsUrl(`/${mine.id}/end`), { ...query, counted_cash: mine.figures.expected_cash });
    expect(res.status, `end the shift left open: ${JSON.stringify(res.body)}`).toBe(302);
}

const shiftDetail = async (api, id) => {
    const res = await apiJson(api, "GET", shiftsUrl(`/${id}`));
    expect(res.status, "shift detail").toBe(200);
    return res.body;
};

/** The day's drawer lines over HTTP (Cash in & out), every page. */
async function linesOn(api, day, query = {}) {
    const state = await ledgerState(api, { date_from: day, date_to: day, per_page: 100, ...query });
    return state.ledger.movements.data;
}

const closeDay = (api, body) => apiJson(api, "POST", ledgerUrl("/close-shift"), body);

/* ------------------------------------------------------------------ */
/* A. A person's own shift, ended and reopened three times, per role   */
/* ------------------------------------------------------------------ */

/** Who approves this person's count in the end: never themselves. */
const APPROVER = { "wallet-cashier": "wallet-manager", "wallet-supervisor": "wallet-manager", "wallet-manager": "wallet-partner", admin: "wallet-partner", super: "wallet-partner" };

for (const account of ["wallet-cashier", "wallet-supervisor", "wallet-manager", "admin", "super"]) {
    test.describe(`Own shift reopened three times as ${account}`, () => {
        test.use({ account });

        test.afterEach(async ({ serverAs }, testInfo) => endOpenShift(await serverAs(account), storeQuery(account, testInfo)));

        test("each end adds a count, each reopen keeps what it showed, and Expected never drifts", async ({ page, serverAs }, testInfo) => {
            // Three ends and two reopens through the UI, each waiting for the page to reload.
            test.setTimeout(150_000);
            const query = storeQuery(account, testInfo);
            const api = await serverAs(account);
            await endOpenShift(api, query);

            const drawer = await DrawerPage.open(page, undefined, query);
            await page.getByTestId("open-start-my-shift").click();
            const start = drawer.dialog("Start my shift");
            await numberInput(start, "my-shift-opening").fill("500");
            expect((await drawer.save("/wallet/shifts/start", () => start.getByTestId("confirm-start-my-shift").click())).status()).toBeLessThan(400);
            await expect(page.getByTestId("my-shift-status")).toHaveText("Open");

            const id = (await drawerProps(api, query)).myShift.id;
            // Short, over, then right: the status follows the last count only.
            const rounds = [
                { counted: 480, status: "needs_review" },
                { counted: 530, status: "needs_review" },
                { counted: 500, status: "closed" },
            ];

            for (const [i, round] of rounds.entries()) {
                if (i > 0) {
                    await page.getByTestId("reopen-my-shift").click();
                    await page.getByTestId("reopen-reason").fill(`Recount ${i}`);
                    expect((await drawer.save("/reopen", () => page.getByTestId("reopen-confirm").click())).status(), `reopen ${i}`).toBeLessThan(400);
                    await expect(page.getByTestId("my-shift-status")).toHaveText("Open");
                    // Reopened, it shows what should be there now, the same as before it ended.
                    await expect(page.getByTestId("my-shift-expected")).toContainText("500");
                }

                await page.getByTestId("open-end-my-shift").click();
                const end = drawer.dialog("End my shift");
                await numberInput(end, "end-shift-counted").fill(String(round.counted));
                expect((await drawer.save("/wallet/shifts/", () => end.getByTestId("confirm-end-shift").click())).status(), `end ${i + 1}`).toBeLessThan(400);

                const detail = await shiftDetail(await serverAs("wallet-manager"), id);
                expect(detail.shift.status, `status after end ${i + 1}`).toBe(round.status);
                expect(detail.shift.expected_cash, `Expected after end ${i + 1}`).toBe(500);
                expect(detail.counts, `counts after end ${i + 1}`).toHaveLength(i + 1);
                expect(detail.counts.map((c) => c.counted_cash)).toEqual(rounds.slice(0, i + 1).map((r) => r.counted));
                expect(detail.counts.every((c) => c.expected_cash === 500), "every count compared with ₱500").toBe(true);
                expect(detail.reopens, `reopens after end ${i + 1}`).toHaveLength(i);
                // Each reopen kept the count it undid.
                detail.reopens.forEach((r, n) => expect(r.before.counted_cash, `reopen ${n + 1} keeps count ${n + 1}`).toBe(rounds[n].counted));
            }

            // Signed off by someone else; after that it can't be reopened.
            const approver = await serverAs(APPROVER[account]);
            expect((await apiJson(approver, "POST", shiftsUrl(`/${id}/approve`), { note: "Three counts checked" })).status, "approve").toBe(302);
            const again = await apiJson(api, "POST", shiftsUrl(`/${id}/reopen`), { ...query, reason: "One more time" });
            expect(again.status, "reopen an approved shift").toBe(422);

            const final = await shiftDetail(approver, id);
            expect(final.shift.status).toBe("approved");
            // The approval lands on the count that stands, the last one.
            expect(final.counts.map((c) => Boolean(c.approved_at))).toEqual([false, false, true]);
        });
    });
}

/* ------------------------------------------------------------------ */
/* B/C. The store's day, closed and reopened again and again           */
/* ------------------------------------------------------------------ */

test.describe("The store's day, closed and reopened three times", () => {
    test.use({ account: "wallet-manager" });

    test("taking the cash out, reopening and ending again keeps the books in step with the drawer", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const date = day(0);
        const api = await serverAs("wallet-manager");

        expectSaved(await postOpening(api, { business_date: date, opening_cash: 500 }), "opening");
        expectSaved(await postLedger(api, { direction: "in", amount: 100, kind: "cash_sale_topup", movement_date: date, notes: "Reopen test float" }), "cash in");
        expect((await walletState(api, date)).cashControl.expected_cash, "Expected before the first close").toBe(600);

        // Round 1 comes out ₱10 short; each reopen takes in a little more cash, counted right.
        const rounds = [
            { cashIn: 0, counted: 590 },
            { cashIn: 50, counted: 50 },
            { cashIn: 30, counted: 30 },
        ];
        const cycles = [];
        for (const [i, round] of rounds.entries()) {
            const n = i + 1;
            if (round.cashIn) {
                expectSaved(await postLedger(api, { direction: "in", amount: round.cashIn, kind: "cash_sale_topup", movement_date: date, notes: `Late sale ${n}` }), `cash in ${n}`);
            }
            const expected = (await walletState(api, date)).cashControl.expected_cash;
            const closed = await closeDay(api, { business_date: date, counted_cash: round.counted, end_shift_action: "cashout_now" });
            const afterClose = (await walletState(api, date)).cashControl;

            const drawer = await DrawerPage.open(page, date);
            const reopened = await drawer.reopenShift(`Late sale, round ${n}`);
            const afterReopen = (await walletState(api, date)).cashControl;

            cycles.push({
                round: n,
                expected,
                counted: round.counted,
                close: closed.status,
                reopen: reopened.status(),
                expectedAfterClose: afterClose.expected_cash,
                expectedAfterReopen: afterReopen.expected_cash,
                countedAfterReopen: afterReopen.counted_cash,
                lastReopen: afterReopen.last_reopen?.reason,
            });
        }

        const lines = await linesOn(api, date);
        const sum = (rows, dir) => rows.filter((l) => l.direction === dir).reduce((s, l) => s + Number(l.amount), 0);
        const cashOuts = lines.filter((l) => l.notes === "AUTO_CC_ENDSHIFT_CASHOUT");
        const differences = lines.filter((l) => l.notes === "AUTO_CC_COUNTED_VARIANCE");
        const cashIns = lines.filter((l) => l.kind === "cash_sale_topup");
        // The opening, the cash taken in, every count's difference, less every cash-out: what's left.
        const left = 500 + sum(cashIns, "in") + sum(differences, "in") - sum(differences, "out") - sum(cashOuts, "out");

        await test.info().attach("store-day-cashout-cycles", {
            body: JSON.stringify({ cycles, cashOuts: cashOuts.map((l) => l.amount), differences: differences.map((l) => `${l.direction} ${l.amount}`), left }, null, 2),
            contentType: "application/json",
        });

        for (const c of cycles) {
            expect(c.close, `close ${c.round}`).toBe(302);
            expect(c.reopen, `reopen ${c.round}`).toBeLessThan(400);
            expect(c.lastReopen, `reopen ${c.round} logged`).toBe(`Late sale, round ${c.round}`);
            expect(c.expectedAfterClose, `the drawer is empty after cash-out ${c.round}`).toBe(0);
            expect(c.countedAfterReopen, `reopen ${c.round} waits for a new count`).toBeNull();
        }
        expect(cycles.map((c) => c.expected), "Expected before each close").toEqual([600, 50, 30]);
        expect(cashOuts.map((l) => Number(l.amount)).sort((a, b) => a - b), "one cash-out per close").toEqual([30, 50, 590]);
        // Round 1's ₱10 short is still on the books after two more counts.
        expect(differences.map((l) => `${l.direction} ${Number(l.amount)}`), "count differences").toEqual(["out 10"]);
        expect(left, "the books say the drawer is empty, like it is").toBe(0);
    });

    test("leaving the cash as the opening, then reopening twice, keeps the float", async ({ serverAs }) => {
        const { day } = dateWindow();
        const date = day(0);
        const api = await serverAs("wallet-manager");

        expectSaved(await postOpening(api, { business_date: date, opening_cash: 200 }), "opening");
        expectSaved(await postLedger(api, { direction: "in", amount: 75, kind: "cash_sale_topup", movement_date: date }), "cash in");

        for (let i = 1; i <= 2; i++) {
            const closed = await closeDay(api, { business_date: date, counted_cash: 275, end_shift_action: "save_as_opening_cash" });
            expect(closed.status, `close ${i}`).toBe(302);
            expectSaved(await postReopen(api, { business_date: date, reason: `Check again ${i}` }), `reopen ${i}`);

            const state = (await walletState(api, date)).cashControl;
            expect(state.is_closed, `open after reopen ${i}`).toBe(false);
            expect(state.opening_cash, `float kept after reopen ${i}`).toBe(275);
            expect(state.expected_cash, `Expected after reopen ${i}`).toBe(275);
            // The old count isn't shown as this day's count any more: the day waits for a new one.
            expect(state.counted_cash, `counted after reopen ${i}`).toBeNull();
        }

        // Straight after a reopen, ending the day without counting again is refused...
        const uncounted = await postEndShift(api, { business_date: date, end_shift_action: "save_as_opening_cash" });
        const state = (await walletState(api, date)).cashControl;
        await test.info().attach("end-without-recount", {
            body: JSON.stringify({ status: uncounted.status, errors: uncounted.body?.errors, opening_after: state.opening_cash, is_closed: state.is_closed }, null, 2),
            contentType: "application/json",
        });
        // ...and must never quietly turn the ₱275 float into ₱0.
        expect(state.opening_cash, "float after ending without a recount").toBe(275);
        expect(uncounted.status, "end the day without counting after a reopen").toBe(422);

        // The next day starts from the float that was left.
        expect((await closeDay(api, { business_date: date, counted_cash: 275, end_shift_action: "save_as_opening_cash" })).status, "end the day").toBe(302);
        const next = (await walletState(api, day(1))).cashControl;
        expect(next.opening_suggestion, "next day's suggested opening").toBe(275);
    });
});

/* ------------------------------------------------------------------ */
/* D. Several people on shift at once                                  */
/* ------------------------------------------------------------------ */

test.describe("Several people on shift at the same store", () => {
    test.use({ account: "wallet-manager" });

    test.afterEach(async ({ serverAs }) => {
        await endOpenShift(await serverAs("wallet-cashier"));
        await endOpenShift(await serverAs("wallet-partner"));
    });

    /**
     * Each cashier has their own drawer, so a second person's opening is more cash at the store.
     * The partner (a second manager) runs no other shift in the suite, so today this is their first
     * shift at the store; a later shift of theirs would reuse the drawer already counted in. Run it
     * after a fresh seed (the default) for that to hold.
     */
    test("a second person's opening and cash line reach the store's Expected, like their shift's", async ({ page, serverAs }, testInfo) => {
        const cashier = await serverAs("wallet-cashier");
        const partner = await serverAs("wallet-partner");
        await endOpenShift(cashier);
        await endOpenShift(partner);
        const today = todayYmd();

        const storeExpected = async () => (await walletState(partner, today)).cashControl.expected_cash;

        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 500 })).status, "cashier starts").toBe(302);
        const e1 = await storeExpected();
        expect((await apiJson(partner, "POST", shiftsUrl("/start"), { opening_cash: 300 })).status, "partner starts").toBe(302);
        const e2 = await storeExpected();
        expectSaved(await postLedger(partner, { direction: "in", amount: 100, kind: "cash_sale_topup", movement_date: today, notes: "Change fund" }), "cash in");
        const e3 = await storeExpected();

        const cashierShift = (await drawerProps(cashier)).myShift;
        const partnerShift = (await drawerProps(partner)).myShift;

        await test.info().attach("store-vs-shifts", {
            body: JSON.stringify({ afterCashierStart: e1, afterPartnerStart: e2, afterCashIn: e3, cashierShift: cashierShift.figures.expected_cash, partnerShift: partnerShift.figures.expected_cash }, null, 2),
            contentType: "application/json",
        });

        expect(cashierShift.figures.expected_cash, "cashier's Expected").toBe(500);
        expect(partnerShift.figures.expected_cash, "partner's Expected (₱300 + ₱100 cash in)").toBe(400);
        expect(e2 - e1, "the partner's opening reaches the store's Expected").toBe(300);
        expect(e3 - e2, "the cash in reaches the store's Expected").toBe(100);

        // View details lists both people with what should be in their drawer.
        await DrawerPage.open(page);
        await page.getByTestId("open-money-by-user").click();
        const list = page.getByTestId("money-by-user");
        await expect(list).toContainText(walletFixture(testInfo).cashierName);
        await expect(list).toContainText(walletFixture(testInfo).partnerName);
    });
});

/* ------------------------------------------------------------------ */
/* E. A shift from an earlier day, reopened                            */
/* ------------------------------------------------------------------ */

test.describe("A shift from an earlier day", () => {
    test.use({ account: "wallet-cashier" });

    test.afterEach(async ({ serverAs }) => endOpenShift(await serverAs("wallet-cashier")));

    test("can't be reopened, so it never takes today's money", async ({ serverAs }) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);

        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 100 })).status, "start").toBe(302);
        const { id } = (await drawerProps(cashier)).myShift;
        expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 100 })).status, "end").toBe(302);

        // Make it yesterday's shift, as if it had been ended last night.
        execSync(
            `php artisan tinker --execute="DB::table('register_shifts')->where('id', ${id})->update(['business_date' => now()->subDay()->toDateString(), 'opened_at' => now()->subDay(), 'closed_at' => now()->subDay()]);"`,
            { stdio: "pipe" },
        );

        // Reopened, it would become the cashier's open shift and take today's sales into yesterday's drawer.
        const reopen = await apiJson(cashier, "POST", shiftsUrl(`/${id}/reopen`), { reason: "Forgot a sale last night" });
        expect(reopen.status, "reopen yesterday's shift").toBe(422);
        expect(reopen.body.errors.shift[0]).toContain("Only today's shifts can be reopened");

        // Today's shift starts as usual.
        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 0 })).status, "start today's shift").toBe(302);
        expect((await drawerProps(cashier)).myShift.business_date, "the shift today's sales go to").toBe(todayYmd());
    });
});

/* ------------------------------------------------------------------ */
/* F. Cash expenses on ending, reopening and ending again               */
/* ------------------------------------------------------------------ */

test.describe("Cash expenses when a shift is ended again", () => {
    test.use({ account: "wallet-cashier" });

    test.afterEach(async ({ serverAs }) => endOpenShift(await serverAs("wallet-cashier")));

    test("an expense paid at the first end counts once, and the reopened shift shows it", async ({ serverAs }) => {
        const cashier = await serverAs("wallet-cashier");
        await endOpenShift(cashier);
        const props = await drawerProps(cashier);
        test.skip(!props.expenseCategories?.length, "no expense categories on this plan");
        const category = props.expenseCategories[0].id;

        expect((await apiJson(cashier, "POST", shiftsUrl("/start"), { opening_cash: 200 })).status, "start").toBe(302);
        const { id } = (await drawerProps(cashier)).myShift;
        const expense = { expense_category_id: category, amount: 50, description: "E2E ice for the cooler" };
        expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 150, expenses: [expense] })).status, "end 1").toBe(302);

        const manager = await serverAs("wallet-manager");
        let detail = await shiftDetail(manager, id);
        expect(detail.shift.status, "₱200 − ₱50 expense, counted ₱150").toBe("closed");
        expect(detail.shift.expected_cash).toBe(150);

        expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/reopen`), { reason: "Check the expense" })).status, "reopen").toBe(302);
        const reopened = (await drawerProps(cashier)).myShift;
        expect(reopened.figures.expected_cash, "Expected while reopened still has the expense").toBe(150);

        expect((await apiJson(cashier, "POST", shiftsUrl(`/${id}/end`), { counted_cash: 150 })).status, "end 2").toBe(302);
        detail = await shiftDetail(manager, id);
        expect(detail.shift.expected_cash, "Expected after ending again").toBe(150);
        expect(detail.cash_lines.filter((l) => l.kind === "expense"), "expense lines on the shift").toHaveLength(1);
    });
});

/* ------------------------------------------------------------------ */
/* G. Who may reopen the store's day                                   */
/* ------------------------------------------------------------------ */

test.describe("Reopening the store's day, by role", () => {
    for (const account of ["wallet-cashier", "wallet-supervisor", "wallet-partner", "admin", "super"]) {
        test(`as ${account}`, async ({ serverAs, observe }, testInfo) => {
            const { day } = dateWindow();
            const date = day(0);
            const manager = await serverAs("wallet-manager");
            expectSaved(await postCounted(manager, { business_date: date, counted_cash: 120 }), "count");
            expectSaved(await postEndShift(manager, { business_date: date, end_shift_action: "save_as_opening_cash" }), "close");

            const api = await serverAs(account);
            const res = await postReopen(api, { ...storeQuery(account, testInfo), business_date: date, reason: `Reopened by ${account}` });
            const state = (await walletState(manager, date)).cashControl;
            expect(res.status, "never a server error").toBeLessThan(500);

            const reopened = state.is_closed === false;
            observe(`${account} reopening the store's day: HTTP ${res.status}, day ${reopened ? "reopened" : "still closed"}`);
            await testInfo.attach("result", { body: JSON.stringify({ account, status: res.status, reopened }), contentType: "application/json" });
            // Ending the day is a manager's; a cashier reopening it is noted, not failed (it may be on purpose).
            if (account !== "wallet-cashier" && account !== "wallet-supervisor") {
                expect(reopened, `${account} reopens the day`).toBe(true);
            }
        });
    }
});
