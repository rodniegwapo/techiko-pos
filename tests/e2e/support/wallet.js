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
export const walletUrl = `/domains/${WALLET_DOMAIN}/wallet/money-movement`;
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

/** Page props for a business date (and optional ledger filters), read over HTTP. */
export async function walletState(api, businessDate, query = {}) {
    const params = new URLSearchParams({ business_date: businessDate, ...query });
    const res = await api.get(`${walletUrl}?${params}`);
    expect(res.status(), "money movement page").toBe(200);
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
/* UI page object                                                      */
/* ------------------------------------------------------------------ */

export class MoneyMovementPage {
    constructor(page) {
        this.page = page;
    }

    static async open(page, businessDate, query = {}) {
        const params = new URLSearchParams(businessDate ? { business_date: businessDate, ...query } : query);
        const wallet = new MoneyMovementPage(page);
        await page.goto(`${walletUrl}${params.size ? `?${params}` : ""}`);
        await expect(page.getByText("Cash control", { exact: true })).toBeVisible();
        return wallet;
    }

    /** Waits for the Inertia reload the page does after a save. */
    waitForReload() {
        return this.page.waitForResponse(
            (r) => r.request().method() === "GET" && new URL(r.url()).pathname === walletUrl && r.request().headers()["x-inertia"],
        );
    }

    async expandCashControl() {
        // The page collapses the panel again whenever it reloads, so retry until it stays open.
        await expect(async () => {
            const toggle = this.page.getByRole("button", { name: "Full cash control" });
            if (await toggle.isVisible()) {
                await toggle.click();
            }
            await expect(this.page.getByText("Set opening cash", { exact: true })).toBeVisible({ timeout: 1000 });
        }).toPass({ timeout: 10_000 });
    }

    /** Expected / Counted / Variance value in the summary strip. */
    summary(label) {
        return this.page.locator(`div:has(> div:text-is("${label}"))`).locator("> div").nth(1);
    }

    card(title) {
        return this.page.locator("div.rounded.border").filter({ has: this.page.getByText(title, { exact: true }) }).last();
    }

    get openingCard() {
        return this.card("Set opening cash");
    }

    get countedCard() {
        return this.card("Submit counted cash");
    }

    async saveOpening(amount, reason = "") {
        await this.expandCashControl();
        await this.openingCard.getByRole("spinbutton").fill(String(amount));
        if (reason) await this.openingCard.getByPlaceholder("Optional reason for override/change").fill(reason);

        const saved = this.page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/cash-ledger/opening-cash"));
        const reloaded = this.waitForReload();
        await this.openingCard.getByRole("button", { name: "Save opening" }).click();
        const res = await saved;
        if (succeeded(res)) await reloaded;
        return res;
    }

    async saveCounted(amount, notes = "") {
        await this.expandCashControl();
        await this.countedCard.getByRole("spinbutton").fill(String(amount));
        if (notes) await this.countedCard.getByPlaceholder("Optional reconciliation notes").fill(notes);

        const saved = this.page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/cash-ledger/counted-cash"));
        const reloaded = this.waitForReload();
        await this.countedCard.getByRole("button", { name: "Save counted cash" }).click();
        const res = await saved;
        if (succeeded(res)) await reloaded;
        return res;
    }

    /** @param {"Cash out now"|"Save as opening cash"} action */
    async endShift(action) {
        await this.page.getByRole("button", { name: "End Shift" }).click();
        const dialog = this.page.getByRole("dialog").filter({ hasText: "End Shift action" });
        await expect(dialog).toBeVisible();
        await dialog.getByText(action, { exact: true }).click();

        const closed = this.page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/cash-ledger/end-shift"));
        const reloaded = this.waitForReload();
        await dialog.getByRole("button", { name: "Confirm End Shift" }).click();
        const res = await closed;
        if (succeeded(res)) await reloaded;
        return res;
    }

    async reopenShift() {
        const reopened = this.page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/cash-ledger/reopen-shift"));
        const reloaded = this.waitForReload();
        await this.page.getByRole("button", { name: "Reopen Shift" }).click();
        const res = await reopened;
        if (succeeded(res)) await reloaded;
        return res;
    }

    /* Ledger */

    /** Opens "Add entry" → Cash in / Cash out / Other entry and returns the dialog. */
    async openEntryDialog(menuItem) {
        await this.page.getByRole("button", { name: /Add entry/ }).hover();
        await this.page.getByRole("menuitem", { name: menuItem }).click();
        const dialog = this.page.getByRole("dialog");
        await expect(dialog).toBeVisible();
        return dialog;
    }

    /**
     * Fills the entry dialog. Fields: direction ("Cash in"/"Cash out"), kind (label), amount,
     * withdrawFrom ("Cash register"/"Terminal / card rail"), rail (card name), date, notes.
     */
    async fillEntry(dialog, { direction, kind, amount, withdrawFrom, rail, date, notes } = {}) {
        if (kind) await pickSelectOption(this.page, dialog, 0, kind);
        if (direction) await dialog.locator(".ant-radio-button-wrapper").filter({ hasText: direction }).first().click();
        if (amount !== undefined) await dialog.getByRole("spinbutton").fill(String(amount));
        if (withdrawFrom) await dialog.locator(".ant-radio-button-wrapper").filter({ hasText: withdrawFrom }).click();
        if (rail) await pickSelectOption(this.page, dialog, 1, rail);
        if (date) await dialog.locator('input[type="date"]').fill(date);
        if (notes) await dialog.getByPlaceholder("Optional memo").fill(notes);
    }

    /**
     * Clicks Save. Returns the POST response (after the page reloads), or null when the dialog
     * blocked it client-side. Returns quickly in the blocked case so short-lived toasts can be checked.
     */
    async saveEntry(dialog) {
        let request = null;
        const onRequest = (r) => {
            if (r.method() === "POST" && new URL(r.url()).pathname === ledgerUrl()) request = r;
        };
        this.page.on("request", onRequest);
        await dialog.getByRole("button", { name: "Save" }).click();
        await this.page.waitForTimeout(500);
        this.page.off("request", onRequest);

        if (!request) return null;

        // Inertia form posts are answered with a redirect back (for successes and validation
        // errors alike) that the browser follows in the same request; wait for the whole chain.
        const res = await request.response();
        let hop = request;
        while (hop.redirectedTo()) {
            hop = hop.redirectedTo();
            await hop.response();
        }
        await this.page.waitForTimeout(300);
        return res;
    }

    async addEntry(menuItem, fields) {
        const dialog = await this.openEntryDialog(menuItem);
        await this.fillEntry(dialog, fields);
        const res = await this.saveEntry(dialog);
        return { res, dialog };
    }

    /** Sets ledger filters and applies them. */
    async filter({ from, to, rail, kind } = {}) {
        const panel = this.page.locator("#cash-ledger");
        if (from !== undefined) await panel.locator('input[type="date"]').nth(0).fill(from);
        if (to !== undefined) await panel.locator('input[type="date"]').nth(1).fill(to);
        if (rail) await pickSelectOption(this.page, panel, 0, rail);
        if (kind) await pickSelectOption(this.page, panel, 1, kind);

        // Wait for this Apply's reload specifically, not an earlier save's redirect back.
        const reloaded = this.page.waitForResponse((r) => {
            const url = new URL(r.url());
            return (
                r.request().method() === "GET" &&
                url.pathname === walletUrl &&
                (from === undefined || url.searchParams.get("date_from") === from) &&
                (to === undefined || url.searchParams.get("date_to") === to)
            );
        });
        await panel.getByRole("button", { name: "Apply" }).click();
        await reloaded;
    }

    get rows() {
        return this.page.locator("#cash-ledger .ant-table-tbody tr.ant-table-row");
    }

    row(text) {
        return this.rows.filter({ hasText: text });
    }

    headline(label) {
        return this.page.locator("#cash-ledger").locator(`div:has(> div:text-is("${label}"))`).locator("> div").nth(1);
    }

    notice(title) {
        return notice(this.page, title);
    }

    toast(text) {
        return toast(this.page, text);
    }
}

export { money };
