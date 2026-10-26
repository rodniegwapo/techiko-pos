import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { expect } from "@playwright/test";
import { apiJson, test } from "./fixtures.js";
import { notice, pickSelectOption, toast } from "./antd.js";
import { responseProps } from "./inertia.js";
import { money, fixtureIds } from "./sales.js";
import { workerNumber } from "./users.js";

export const WALLET_DOMAIN = "jollibee-corp";
/** The old all-in-one page; it now sends you on to the page below that does that job. */
export const walletUrl = `/domains/${WALLET_DOMAIN}/wallet/money-movement`;
export const drawerUrl = `/domains/${WALLET_DOMAIN}/wallet/drawer`;
export const cashInOutUrl = `/domains/${WALLET_DOMAIN}/wallet/cash-in-out`;
export const balancesUrl = `/domains/${WALLET_DOMAIN}/wallet/balances`;
export const historyUrl = `/domains/${WALLET_DOMAIN}/wallet/history`;
export const ledgerUrl = (path = "") => `/domains/${WALLET_DOMAIN}/wallet/cash-ledger${path}`;
export const cardTypesUrl = (path = "") => `/domains/${WALLET_DOMAIN}/payment-card-types${path}`;
export const cardTypeUrl = (id, path = "") => cardTypesUrl(`/${id}${path}`);

/** This worker's seeded store, managers and card type (E2EWalletSeeder). */
export function walletFixture(testInfo) {
    return fixtureIds().wallet.workers[workerNumber(testInfo.parallelIndex)];
}

/* ------------------------------------------------------------------ */
/* Business dates                                                      */
/* ------------------------------------------------------------------ */

/**
 * Hands each test its own 10-day window of past business dates, moving backwards in time.
 *
 * Wallet state is keyed by store + date, and "bridge" / opening suggestions look at the most
 * recent *earlier* counted day. Because later tests always get earlier windows, no test ever
 * sees another test's counts as its history.
 *
 * The counter lives in a file per worker, because Playwright restarts a worker after a failure
 * (an in-memory counter would reset and reuse dates already closed or counted). global-setup
 * deletes the files when it re-seeds (which wipes the stores); with E2E_SKIP_SEED the counter
 * keeps going so dates stay fresh.
 */
const BASE_DATE = Date.UTC(2025, 11, 31); // 2025-12-31

export const dateCounterFile = (n) => join(tmpdir(), `techiko-e2e-wallet-dates-${n}.txt`);

function nextWindowIndex() {
    const file = dateCounterFile(workerNumber(test.info().parallelIndex));
    const index = existsSync(file) ? Number(readFileSync(file, "utf8")) || 0 : 0;
    writeFileSync(file, String(index + 1));
    return index;
}

export function dateWindow() {
    const start = BASE_DATE - (nextWindowIndex() * 10 + 9) * 86_400_000;
    return {
        /** `day(0)` is the window's first date, up to `day(9)`. */
        day: (offset = 0) => new Date(start + offset * 86_400_000).toISOString().slice(0, 10),
    };
}

export const todayYmd = () => new Date().toISOString().slice(0, 10);
export const futureYmd = () => new Date(Date.now() + 2 * 86_400_000).toISOString().slice(0, 10);

/* ------------------------------------------------------------------ */
/* API helpers                                                         */
/* ------------------------------------------------------------------ */

/** Today's drawer props (cashControl) for a business date, read over HTTP. */
export async function walletState(api, businessDate, query = {}) {
    const params = new URLSearchParams({ business_date: businessDate, ...query });
    const res = await api.get(`${drawerUrl}?${params}`);
    expect(res.status(), "today's drawer page").toBe(200);
    return responseProps(res);
}

/** Cash in & out props (ledger) for the given filters, read over HTTP. */
export async function ledgerState(api, query = {}) {
    const params = new URLSearchParams(query);
    const res = await api.get(`${cashInOutUrl}?${params}`);
    expect(res.status(), "cash in & out page").toBe(200);
    return responseProps(res);
}

export const postLedger = (api, body) => apiJson(api, "POST", ledgerUrl(), body);
export const postOpening = (api, body) => apiJson(api, "POST", ledgerUrl("/opening-cash"), body);
export const postCounted = (api, body) => apiJson(api, "POST", ledgerUrl("/counted-cash"), body);
export const postEndShift = (api, body) => apiJson(api, "POST", ledgerUrl("/end-shift"), body);
export const postReopen = (api, body) => apiJson(api, "POST", ledgerUrl("/reopen-shift"), body);

/**
 * Saves answer with a redirect back to the page, so the browser sees the POST as a 302
 * (axios then follows it). Anything below 400 is a success.
 */
const succeeded = (res) => res.status() < 400;

/** A saved success is a redirect back (302) to the page. */
export function expectSaved(res, what) {
    expect([200, 302], `${what}: got ${res.status} ${JSON.stringify(res.body)?.slice(0, 200)}`).toContain(res.status);
}

/* ------------------------------------------------------------------ */
/* UI page objects                                                     */
/* ------------------------------------------------------------------ */

/** An antd number input by test id (the id lands on the <input> itself). */
export const numberInput = (scope, testId) => scope.getByTestId(testId).locator("input").or(scope.getByTestId(testId)).first();

/** Types into an antd date picker inside `scope` (the nth one) and confirms. */
export async function fillDatePicker(scope, value, nth = 0) {
    const input = scope.locator(".ant-picker input").nth(nth);
    await input.click();
    await input.fill(value);
    await input.press("Enter");
}

/** Today's drawer: Expected / Counted / Short-Over, and the shift actions. */
export class DrawerPage {
    constructor(page) {
        this.page = page;
    }

    static async open(page, businessDate, query = {}) {
        const params = new URLSearchParams(businessDate ? { business_date: businessDate, ...query } : query);
        await page.goto(`${drawerUrl}${params.size ? `?${params}` : ""}`);
        await expect(page.getByTestId("drawer-expected")).toBeVisible();
        return new DrawerPage(page);
    }

    /** Waits for the reload the page does after a save. */
    waitForReload() {
        return this.page.waitForResponse(
            (r) => r.request().method() === "GET" && new URL(r.url()).pathname === drawerUrl && r.request().headers()["x-inertia"],
        );
    }

    get expected() {
        return this.page.getByTestId("drawer-expected");
    }

    get counted() {
        return this.page.getByTestId("drawer-counted");
    }

    /** "Short ₱50.00", "Over ₱60.00", "Balanced" or "Not counted". */
    get result() {
        return this.page.getByTestId("drawer-result");
    }

    /** opening | sales | in | out */
    step(key) {
        return this.page.getByTestId(`step-${key}`);
    }

    dialog(title) {
        return this.page.getByRole("dialog").filter({ hasText: title });
    }

    async save(path, act) {
        const saved = this.page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes(path));
        const reloaded = this.waitForReload();
        await act();
        const res = await saved;
        if (succeeded(res)) await reloaded;
        return res;
    }

    async openSetOpening() {
        await this.page.getByTestId("open-set-opening").click();
        const dialog = this.dialog("Set opening cash");
        await expect(dialog).toBeVisible();
        return dialog;
    }

    async setOpening(amount, reason = "") {
        const dialog = await this.openSetOpening();
        await numberInput(dialog, "opening-amount").fill(String(amount));
        if (reason) await dialog.getByPlaceholder("Why it changed (optional)").fill(reason);
        return this.save("/cash-ledger/opening-cash", () => dialog.getByRole("button", { name: "Save opening" }).click());
    }

    async openCountCash() {
        await this.page.getByTestId("open-count-cash").click();
        const dialog = this.dialog("Count cash");
        await expect(dialog).toBeVisible();
        return dialog;
    }

    async countCash(amount, notes = "") {
        const dialog = await this.openCountCash();
        await numberInput(dialog, "count-amount").fill(String(amount));
        if (notes) await dialog.getByPlaceholder("Notes (optional)").fill(notes);
        return this.save("/cash-ledger/counted-cash", () => dialog.getByRole("button", { name: "Save count" }).click());
    }

    /** @param {"Take the cash out"|"Leave it as the next shift's opening"} choice */
    async endShift(choice) {
        await this.page.getByTestId("open-end-shift").click();
        const dialog = this.dialog("End shift");
        await expect(dialog).toBeVisible();
        await dialog.getByTestId(choice === "Take the cash out" ? "end-shift-cashout" : "end-shift-keep").click();
        return this.save("/cash-ledger/close-shift", () => dialog.getByTestId("confirm-end-shift").click());
    }

    /** Reopening asks why; the reason is kept in the reopen log. */
    async reopenShift(reason = "Counted wrong") {
        await this.page.getByTestId("reopen-shift").click();
        await this.page.getByTestId("reopen-reason").fill(reason);
        return this.save("/cash-ledger/reopen-shift", () => this.page.getByTestId("reopen-confirm").click());
    }

    notice(title) {
        return notice(this.page, title);
    }
}

/** Cash in & out: the drawer's lines, adding cash in / cash out / moving money on a channel. */
export class CashInOutPage {
    constructor(page) {
        this.page = page;
    }

    static async open(page, query = {}) {
        const params = new URLSearchParams(query);
        await page.goto(`${cashInOutUrl}${params.size ? `?${params}` : ""}`);
        await expect(page.getByTestId("cash-filter-type")).toBeVisible();
        return new CashInOutPage(page);
    }

    /** Opens + Cash in / − Cash out / Move money and returns the dialog. */
    async openDialog(mode) {
        await this.page.getByTestId({ in: "add-cash-in", out: "add-cash-out", move: "move-money" }[mode]).click();
        const dialog = this.page.getByRole("dialog");
        await expect(dialog).toBeVisible();
        return dialog;
    }

    /**
     * Fields: type ("Added float" | "Owner took cash" | "Correction"), from ("Cash drawer" |
     * "A payment channel"), direction ("Into the channel" | "Out of the channel"), channel, amount, date, notes.
     */
    async fill(dialog, { type, from, direction, channel, amount, date, notes } = {}) {
        if (type) await dialog.locator(".ant-radio-button-wrapper").filter({ hasText: type }).click();
        if (from) await dialog.locator(".ant-radio-wrapper").filter({ hasText: from }).click();
        if (direction) await dialog.locator(".ant-radio-button-wrapper").filter({ hasText: direction }).click();
        if (channel) await pickSelectOption(this.page, dialog, 0, channel);
        if (amount !== undefined) await numberInput(dialog, "cash-amount").fill(String(amount));
        if (date) await fillDatePicker(dialog, date);
        if (notes) await dialog.getByTestId("cash-notes").fill(notes);
    }

    /** Clicks Save. Returns the POST response, or null when the dialog stopped it client-side. */
    async save(dialog) {
        let request = null;
        const onRequest = (r) => {
            if (r.method() === "POST" && new URL(r.url()).pathname === ledgerUrl()) request = r;
        };
        this.page.on("request", onRequest);
        await dialog.getByRole("button", { name: "Save" }).click();
        await this.page.waitForTimeout(500);
        this.page.off("request", onRequest);
        if (!request) return null;

        // Inertia posts are answered with a redirect back; wait for the whole chain.
        const res = await request.response();
        let hop = request;
        while (hop.redirectedTo()) {
            hop = hop.redirectedTo();
            await hop.response();
        }
        await this.page.waitForTimeout(300);
        return res;
    }

    async add(mode, fields) {
        const dialog = await this.openDialog(mode);
        await this.fill(dialog, fields);
        const res = await this.save(dialog);
        return { res, dialog };
    }

    /** Sets the filters; the page applies them by itself shortly after they change. */
    async filter({ from, to, type } = {}) {
        const main = this.page.getByRole("main");
        if (type) await pickSelectOption(this.page, main, 0, type);
        if (from !== undefined) await fillDatePicker(main, from, 0);
        if (to !== undefined) await fillDatePicker(main, to, 1);
        await expect(async () => {
            const url = new URL(this.page.url());
            expect(url.pathname).toBe(cashInOutUrl);
            if (from !== undefined) expect(url.searchParams.get("date_from")).toBe(from);
            if (to !== undefined) expect(url.searchParams.get("date_to")).toBe(to);
        }).toPass({ timeout: 10_000 });
        await this.page.waitForLoadState("networkidle");
        await this.page.waitForTimeout(300);
    }

    get rows() {
        return this.page.locator("[data-testid=cash-lines] .ant-table-tbody tr.ant-table-row");
    }

    row(text) {
        return this.rows.filter({ hasText: text });
    }

    toast(text) {
        return toast(this.page, text);
    }
}

export { money };
