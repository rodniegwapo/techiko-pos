import { test, expect } from "../support/fixtures.js";
import {
    dateWindow,
    expectSaved,
    MoneyMovementPage,
    money,
    postLedger,
} from "../support/wallet.js";

/**
 * The money movement page, where the rest of the wallet suite doesn't already reach.
 *
 * Cash control and the ledger's entries and filters are covered by cash-control.spec.js and
 * ledger.spec.js, who may open the page at all by access.spec.js, and what the endpoints refuse by
 * api-validation.spec.js. What is left, and what this covers, is the ledger's paging — including
 * whether the day being looked at survives it — a drawer counted over rather than short, and the
 * way through to the card terminals page.
 *
 * Each test takes its own store and its own window of business dates (support/wallet.js), so the
 * entries it counts are only ever its own.
 */

const LEDGER_PAGE_SIZE = 20;

/** Posts `count` ledger entries on one date, each a different amount so the rows can be told apart. */
async function fillLedger(api, date, count) {
    for (let n = 1; n <= count; n++) {
        const res = await postLedger(api, {
            direction: "in",
            amount: n,
            kind: "adjustment",
            movement_date: date,
            notes: `E2E paging ${n}`,
        });
        expectSaved(res, `ledger entry ${n}`);
    }
}

test.describe("The ledger's paging", () => {
    test.use({ account: "wallet-manager" });

    /** Turns to the ledger's second page and waits for the entries the server sends back. */
    async function turnToPageTwo(page) {
        const loaded = page.waitForResponse(
            (r) => r.request().method() === "GET" && new URL(r.url()).searchParams.get("page") === "2",
        );
        await page.locator("#cash-ledger .ant-pagination-item-2").click();
        await loaded;
    }

    test("twenty entries to a page, and the next page is the ones after them", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const api = await serverAs("wallet-manager");
        await fillLedger(api, day(0), LEDGER_PAGE_SIZE + 1);

        const wallet = await MoneyMovementPage.open(page, day(0));
        await wallet.filter({ from: day(0), to: day(0) });

        await expect(wallet.rows).toHaveCount(LEDGER_PAGE_SIZE);
        const firstRow = await wallet.rows.first().innerText();

        await turnToPageTwo(page);

        // The entries are newest first and all twenty-one fall on the one day, so the last of them
        // — the smallest amount, entered first — is the one left over for the second page.
        await expect(wallet.rows).toHaveCount(1);
        await expect(wallet.rows.first()).not.toHaveText(firstRow);
        await expect(wallet.rows.first()).toContainText(money(1));
    });

    test("turning a page keeps the day being looked at and the filters", async ({ page, serverAs }) => {
        const { day } = dateWindow();
        const api = await serverAs("wallet-manager");
        await fillLedger(api, day(0), LEDGER_PAGE_SIZE + 1);
        // A second day's entry, which the filter must go on excluding after the page is turned.
        expectSaved(
            await postLedger(api, {
                direction: "in",
                amount: 999,
                kind: "adjustment",
                movement_date: day(1),
                notes: "E2E the next day",
            }),
            "entry on the next day",
        );

        const wallet = await MoneyMovementPage.open(page, day(0));
        await wallet.saveOpening(300);
        await wallet.filter({ from: day(0), to: day(0) });
        const expectedBefore = await wallet.summary("Expected").innerText();

        await turnToPageTwo(page);

        // Cash control is keyed to a business date and the ledger to its filters; turning a page
        // must not quietly move the reader to another day's cash or widen what they are looking at.
        await expect(page).toHaveURL(new RegExp(`business_date=${day(0)}`));
        await expect(wallet.summary("Expected"), "still this day's cash").toHaveText(expectedBefore);
        await expect(page.locator('input[type="date"]').first()).toHaveValue(day(0));
        await expect(page).toHaveURL(new RegExp(`date_from=${day(0)}`));
        await expect(page).toHaveURL(new RegExp(`date_to=${day(0)}`));
        await expect(wallet.rows.first(), "the entries left over from the first page").toBeVisible();
        await expect(wallet.row(money(999)), "and still not the next day's").toHaveCount(0);
    });
});

test.describe("A drawer counted over", () => {
    test.use({ account: "wallet-manager" });

    test("shows a variance the other way and posts it to the ledger", async ({ page }) => {
        const { day } = dateWindow();
        const wallet = await MoneyMovementPage.open(page, day(0));
        await wallet.saveOpening(500);

        const res = await wallet.saveCounted(560, "Over by 60");

        // The short count is covered elsewhere; this is the drawer holding more than it should,
        // which has to be recorded just as plainly.
        expect(res.status()).toBeLessThan(400);
        await expect(wallet.summary("Counted")).toHaveText(money(560));
        await expect(wallet.summary("Variance")).toHaveText(money(60));

        await wallet.filter({ from: day(0), to: day(0) });
        await expect(wallet.row("Counted cash variance")).toHaveCount(1);
        await expect(wallet.row("Counted cash variance")).toContainText(money(60));
        await expect(
            wallet.row("Counted cash variance"),
            "counted in, not out",
        ).not.toContainText(`−${money(60)}`);
    });

    test("the ledger net rises by the overage", async ({ page }) => {
        const { day } = dateWindow();
        const wallet = await MoneyMovementPage.open(page, day(0));
        await wallet.saveOpening(200);

        await wallet.saveCounted(275);

        await wallet.filter({ from: day(0), to: day(0) });
        // Opening cash and the overage, and nothing else on this day.
        await expect(wallet.headline("Ledger net")).toHaveText(money(275));
    });
});

test.describe("Finding the way to card terminals", () => {
    test.use({ account: "wallet-manager" });

    test("the page offers the card terminals it sends you to", async ({ page }) => {
        const { day } = dateWindow();
        await MoneyMovementPage.open(page, day(0));

        await expect(page.getByText("Need to edit card rails?")).toBeVisible();
        await page.getByRole("button", { name: "Open card terminals" }).click();

        await expect(page).toHaveURL(/\/payment-card-types/);
        await expect(page.getByRole("heading", { name: "Payment card types" })).toBeVisible();
    });

    test("and takes the day being looked at with it", async ({ page }) => {
        const { day } = dateWindow();
        await MoneyMovementPage.open(page, day(0));

        await page.getByRole("button", { name: "Open card terminals" }).click();

        await expect(page).toHaveURL(new RegExp(`business_date=${day(0)}`));
    });
});
