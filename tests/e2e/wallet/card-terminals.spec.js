import { test, expect, apiJson } from "../support/fixtures.js";
import { notice } from "../support/antd.js";
import { pageProps } from "../support/inertia.js";
import { cardTypeUrl, cardTypesUrl, money, walletFixture, walletUrl } from "../support/wallet.js";

/** Unique card type name per test, so parallel and repeated runs never collide. */
const uniqueName = (label) => `E2E Terminal ${label} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`;

const cardRows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");
const cardRow = (page, name) => cardRows(page).filter({ has: page.getByText(name, { exact: true }) });

async function openCardTerminals(page, query = "") {
    await page.goto(`${cardTypesUrl()}${query}`);
    await expect(page.getByRole("heading", { name: "Payment card types" })).toBeVisible();
}

/** Opens Add/Edit, types the name (and active state when editing) and saves. Returns the request's response. */
async function saveCardTypeDialog(page, { name, active } = {}) {
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    if (name !== undefined) await dialog.getByPlaceholder("e.g. BDO POS, Visa terminal").fill(name);
    if (active !== undefined) {
        const toggle = dialog.getByRole("switch");
        if ((await toggle.getAttribute("aria-checked")) !== String(active)) await toggle.click();
    }

    // Detect the request quickly: when the dialog blocks saving, the warning it shows fades within seconds.
    let request = null;
    const onRequest = (r) => {
        if (["POST", "PUT"].includes(r.method()) && new URL(r.url()).pathname.startsWith(cardTypesUrl())) request = r;
    };
    page.on("request", onRequest);
    await dialog.getByRole("button", { name: "Save" }).click();
    await page.waitForTimeout(500);
    page.off("request", onRequest);
    return request ? request.response() : null;
}

test.describe("Card terminals page", () => {
    test.use({ account: "wallet-manager" });

    test("lists the store's card types with their status", async ({ page }, testInfo) => {
        await openCardTerminals(page);

        const props = await pageProps(page);
        expect(props.activeLocation.id).toBe(walletFixture(testInfo).locationId);
        await expect(cardRow(page, "E2E Wallet Visa")).toContainText("Active");
        await expect(cardRow(page, "E2E Wallet Mastercard")).toBeVisible();
        // Another store's card types are never listed.
        await expect(cardRow(page, "E2E Visa")).toHaveCount(0);
    });

    test("shows today's and yesterday's paid credit sales", async ({ page }, testInfo) => {
        const { cardSales } = walletFixture(testInfo);
        await openCardTerminals(page);

        const credit = page.locator("div.rounded-lg").filter({ has: page.getByText("Paid credit sales (charge to account)") }).first();
        const amount = (label, value) => new RegExp(`${label}\\s*${money(value).replace(".", "\\.")}`);
        await expect(credit).toContainText(amount("Today", cardSales.creditToday));
        await expect(credit).toContainText(amount("Yesterday", cardSales.creditYesterday));
    });

    test("links to money movement", async ({ page }) => {
        await openCardTerminals(page);

        await page.getByRole("button", { name: "Open money movement" }).click();

        await expect(page).toHaveURL(new RegExp(walletUrl));
        await expect(page.getByRole("heading", { name: "Ledger movements" })).toBeVisible();
    });

    test.describe("adding a card type", () => {
        test("a name is required", async ({ page }) => {
            await openCardTerminals(page);
            await page.getByRole("button", { name: "Add card type" }).click();

            const res = await saveCardTypeDialog(page, { name: "   " });

            expect(res, "no request without a name").toBeNull();
            await expect(notice(page, "Name is required.")).toBeVisible();
            await expect(page.getByRole("dialog")).toBeVisible();
        });

        test("creates an active card type in the manager's store", async ({ page, serverAs }, testInfo) => {
            const name = uniqueName("Add");
            await openCardTerminals(page);
            await page.getByRole("button", { name: "Add card type" }).click();
            await expect(page.getByRole("dialog")).toContainText("Add card type");

            const res = await saveCardTypeDialog(page, { name });

            expect(res?.status()).toBe(201);
            await expect(notice(page, "Card type created.")).toBeVisible();
            await expect(cardRow(page, name)).toContainText("Active");

            const api = await serverAs("wallet-manager");
            const list = await apiJson(api, "GET", cardTypesUrl("/list"));
            const created = list.body.data.find((c) => c.name === name);
            expect(created, "offered to cashiers at checkout").toBeTruthy();
            expect((await res.json()).data.location_id).toBe(walletFixture(testInfo).locationId);
        });

        test("a name already used in the store is rejected with a message", async ({ page }) => {
            await openCardTerminals(page);
            await page.getByRole("button", { name: "Add card type" }).click();

            const res = await saveCardTypeDialog(page, { name: "E2E Wallet Visa" });

            expect(res?.status()).toBe(422);
            await expect(notice(page, "A card type with this name already exists at this store.")).toBeVisible();
            await expect(cardRow(page, "E2E Wallet Visa")).toHaveCount(1);
        });

        test("the name is trimmed", async ({ page }) => {
            const name = uniqueName("Trim");
            await openCardTerminals(page);
            await page.getByRole("button", { name: "Add card type" }).click();

            await saveCardTypeDialog(page, { name: `   ${name}   ` });

            await expect(cardRow(page, name)).toHaveCount(1);
        });
    });

    test.describe("editing a card type", () => {
        test("renames and deactivates it", async ({ page, serverAs }) => {
            const api = await serverAs("wallet-manager");
            const original = uniqueName("Edit");
            const created = await apiJson(api, "POST", cardTypesUrl(), { name: original });
            const renamed = `${original} Renamed`;
            await openCardTerminals(page);

            await cardRow(page, original).getByRole("button", { name: "Edit card type" }).click();
            await expect(page.getByRole("dialog")).toContainText("Edit card type");
            await expect(page.getByRole("dialog").getByPlaceholder("e.g. BDO POS, Visa terminal")).toHaveValue(original);
            const res = await saveCardTypeDialog(page, { name: renamed, active: false });

            expect(res?.status()).toBe(200);
            await expect(notice(page, "Card type updated.")).toBeVisible();
            await expect(cardRow(page, renamed)).toContainText("Inactive");
            await expect(cardRow(page, original)).toHaveCount(0);

            const list = await apiJson(api, "GET", cardTypesUrl("/list"));
            expect(list.body.data.map((c) => c.id), "inactive types aren't offered at checkout").not.toContain(created.body.data.id);
        });

        test("the add dialog has no active switch", async ({ page }) => {
            await openCardTerminals(page);
            await page.getByRole("button", { name: "Add card type" }).click();

            await expect(page.getByRole("dialog").getByRole("switch")).toHaveCount(0);
        });
    });

    test.describe("removing a card type", () => {
        test("asks for confirmation, and Cancel keeps the card type", async ({ page, serverAs }) => {
            const api = await serverAs("wallet-manager");
            const name = uniqueName("Keep");
            await apiJson(api, "POST", cardTypesUrl(), { name });
            await openCardTerminals(page);
            let deleted = false;
            page.on("request", (r) => {
                if (r.method() === "DELETE") deleted = true;
            });

            await cardRow(page, name).getByRole("button", { name: "Remove card type" }).click();
            const confirm = page.getByRole("dialog").filter({ hasText: `Remove "${name}"?` });
            await expect(confirm).toBeVisible();
            await confirm.getByRole("button", { name: "Cancel" }).click();

            await expect(confirm).toBeHidden();
            expect(deleted, "nothing is removed without confirming").toBe(false);
            await expect(cardRow(page, name)).toHaveCount(1);
        });

        test("an unused card type is deleted after confirming", async ({ page, serverAs }) => {
            const api = await serverAs("wallet-manager");
            const name = uniqueName("Delete");
            const created = await apiJson(api, "POST", cardTypesUrl(), { name });
            await openCardTerminals(page);

            await cardRow(page, name).getByRole("button", { name: "Remove card type" }).click();
            const removed = page.waitForResponse((r) => r.request().method() === "DELETE");
            await page.getByRole("dialog").filter({ hasText: `Remove "${name}"?` }).getByRole("button", { name: "Remove" }).click();

            expect((await removed).status()).toBe(200);
            await expect(notice(page, "Card type deleted.")).toBeVisible();
            await expect(cardRow(page, name)).toHaveCount(0);
            const update = await apiJson(api, "PUT", cardTypeUrl(created.body.data.id), { name: "gone" });
            expect(update.status).toBe(404);
        });

        test("a card type used on sales is deactivated, and the manager is told so", async ({ page, serverAs }, testInfo) => {
            const api = await serverAs("wallet-manager");
            await openCardTerminals(page);

            try {
                await cardRow(page, "E2E Wallet Mastercard").getByRole("button", { name: "Remove card type" }).click();
                const removed = page.waitForResponse((r) => r.request().method() === "DELETE");
                await page.getByRole("dialog").filter({ hasText: 'Remove "E2E Wallet Mastercard"?' }).getByRole("button", { name: "Remove" }).click();

                const res = await removed;
                expect(res.status()).toBe(200);
                expect((await res.json()).message).toContain("deactivated");
                await expect(cardRow(page, "E2E Wallet Mastercard"), "kept for past sales").toContainText("Inactive");
                await expect(
                    notice(page, "deactivated"),
                    "the notification should say it was deactivated, not deleted",
                ).toBeVisible();
            } finally {
                // Other tests in this worker expect the seeded Mastercard to be active.
                await apiJson(api, "PUT", cardTypeUrl(walletFixture(testInfo).otherCardTypeId), { is_active: true });
            }
        });
    });
});

test.describe("Card payment details page", () => {
    test.use({ account: "wallet-manager" });

    const historyRows = (page) => page.locator(".ant-table-tbody tr.ant-table-row");

    async function openDetails(page, testInfo) {
        const moneyLoaded = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith("/money"));
        await page.goto(cardTypeUrl(walletFixture(testInfo).cardTypeId, "/details"));
        await moneyLoaded;
        await expect(page.getByText("Transaction history (paid card sales)")).toBeVisible();
    }

    async function applyHistory(page, action) {
        const loaded = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith("/money"));
        await action();
        return loaded;
    }

    test("opens from the card terminals list", async ({ page }) => {
        await openCardTerminals(page);

        await cardRow(page, "E2E Wallet Visa").getByRole("button", { name: "View payment details" }).click();

        await expect(page).toHaveURL(/\/details/);
        await expect(page.locator(".ant-breadcrumb")).toContainText("E2E Wallet Visa");
    });

    test("lists only this terminal's paid card sales, 20 per page", async ({ page }, testInfo) => {
        const { cardSales } = walletFixture(testInfo);
        await openDetails(page, testInfo);

        await expect(historyRows(page)).toHaveCount(20);
        await expect(page.getByText(cardSales.recent[0].invoice)).toBeVisible();
        await expect(page.getByText(cardSales.otherCardInvoice), "other terminal").toHaveCount(0);
        await expect(page.getByText(cardSales.pendingInvoice), "unpaid sale").toHaveCount(0);

        await applyHistory(page, () => page.locator(".ant-pagination-item-2").click());
        await expect(historyRows(page)).toHaveCount(cardSales.total - 20);
    });

    test("newest sales come first with their amounts", async ({ page }, testInfo) => {
        const { cardSales } = walletFixture(testInfo);
        await openDetails(page, testInfo);

        await expect(historyRows(page).first()).toContainText(cardSales.recent[0].invoice);
        await expect(historyRows(page).first()).toContainText(money(cardSales.recent[0].amount));
        await expect(historyRows(page).nth(1)).toContainText(cardSales.recent[1].invoice);
    });

    test("searching by invoice narrows the history", async ({ page }, testInfo) => {
        const { cardSales } = walletFixture(testInfo);
        await openDetails(page, testInfo);

        await page.getByPlaceholder("Search invoice").fill(cardSales.recent[1].invoice);
        await applyHistory(page, () => page.getByPlaceholder("Search invoice").press("Enter"));

        await expect(historyRows(page)).toHaveCount(1);
        await expect(historyRows(page)).toContainText(money(cardSales.recent[1].amount));

        await applyHistory(page, () => page.getByRole("button", { name: "Clear" }).click());
        await expect(historyRows(page)).toHaveCount(20);
    });

    test("a search with no match shows the empty state", async ({ page }, testInfo) => {
        await openDetails(page, testInfo);

        await page.getByPlaceholder("Search invoice").fill("NO-SUCH-INVOICE");
        await applyHistory(page, () => page.getByRole("button", { name: "Apply" }).click());

        await expect(page.getByText("No transactions for this card type yet.")).toBeVisible();
    });

    test("the date range filters the history", async ({ page }, testInfo) => {
        const { cardSales } = walletFixture(testInfo);
        await openDetails(page, testInfo);
        const ymd = (daysAgo) => new Date(Date.now() - daysAgo * 86_400_000).toISOString().slice(0, 10);
        const history = page.getByText("Transaction history (paid card sales)").locator("..");

        await history.locator('input[type="date"]').nth(0).fill(ymd(1));
        await history.locator('input[type="date"]').nth(1).fill(ymd(0));
        await applyHistory(page, () => page.getByRole("button", { name: "Apply" }).click());

        await expect(historyRows(page)).toHaveCount(2);
        await expect(page.getByText(cardSales.recent[2].invoice)).toHaveCount(0);
    });

    test("an end date before the start date is explained", async ({ page }, testInfo) => {
        await openDetails(page, testInfo);
        const history = page.getByText("Transaction history (paid card sales)").locator("..");
        const ymd = (daysAgo) => new Date(Date.now() - daysAgo * 86_400_000).toISOString().slice(0, 10);

        await history.locator('input[type="date"]').nth(0).fill(ymd(1));
        await history.locator('input[type="date"]').nth(1).fill(ymd(5));
        const res = await applyHistory(page, () => page.getByRole("button", { name: "Apply" }).click());

        expect(res.status()).toBe(422);
        await expect(notice(page, "The history end date must be on or after the start date.")).toBeVisible();
    });

    test("the breadcrumb returns to card terminals", async ({ page }, testInfo) => {
        await openDetails(page, testInfo);

        await page.locator(".ant-breadcrumb").getByText("Card terminals").click();

        await expect(page).toHaveURL(new RegExp(`${cardTypesUrl()}(\\?|$)`));
        await expect(page.getByRole("heading", { name: "Payment card types" })).toBeVisible();
    });
});
