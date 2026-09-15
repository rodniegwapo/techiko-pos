import { test, expect, apiJson } from "../support/fixtures.js";
import { MoneyMovementPage, dateWindow, futureYmd, ledgerUrl, walletFixture, walletUrl } from "../support/wallet.js";
import { pageProps } from "../support/inertia.js";

const sidebarItem = (page, title) =>
    page.getByRole("complementary").getByRole("menuitem", { name: new RegExp(`^${title}(\\s|$)`) });

test.describe("Money movement access", () => {
    test("guests are sent to login", async ({ page }) => {
        await page.goto(walletUrl);
        await expect(page).toHaveURL(/\/login$/);
    });

    test.describe("as a manager", () => {
        test.use({ account: "wallet-manager" });

        test("opens the page for their own store", async ({ page }, testInfo) => {
            const wallet = await MoneyMovementPage.open(page);

            await expect(page.getByRole("main").getByText("Money movement", { exact: true }).first()).toBeVisible();
            await expect(page.getByRole("heading", { name: "Ledger movements" })).toBeVisible();
            await expect(page.getByRole("button", { name: /Add entry/ })).toBeEnabled();
            await expect(page.getByRole("button", { name: "End Shift" })).toBeVisible();

            const props = await pageProps(page);
            expect(props.activeLocation.id).toBe(walletFixture(testInfo).locationId);
            expect(props.canViewMoneyMovement).toBe(true);
            expect(wallet).toBeTruthy();
        });

        test("the sidebar links to money movement", async ({ page }) => {
            await MoneyMovementPage.open(page);

            const sidebar = page.getByRole("complementary");
            await sidebar.getByText("Cash & wallet", { exact: true }).click();
            await expect(sidebar.getByText("Money movement", { exact: true })).toBeVisible();
        });

        test("defaults the business date to today", async ({ page }) => {
            await MoneyMovementPage.open(page);

            const props = await pageProps(page);
            expect(props.cashControl.business_date).toBe(new Date().toISOString().slice(0, 10));
        });

        test("a future business date is rejected", async ({ serverAs }) => {
            const api = await serverAs("wallet-manager");

            const res = await apiJson(api, "GET", `${walletUrl}?business_date=${futureYmd()}`);

            expect(res.status).toBe(422);
            expect(Object.keys(res.body.errors)).toContain("business_date");
        });

        test("can't view another store by passing its location_id", async ({ page }, testInfo) => {
            const others = Object.values(
                (await import("../support/sales.js")).fixtureIds().wallet.workers,
            ).filter((w) => w.locationId !== walletFixture(testInfo).locationId);

            await page.goto(`${walletUrl}?location_id=${others[0].locationId}`);

            const props = await pageProps(page);
            expect(props.activeLocation.id, "a manager is restricted to their assigned store").toBe(walletFixture(testInfo).locationId);
        });
    });

    test("an admin can open the page", async ({ serverAs }) => {
        const api = await serverAs("admin");
        const res = await api.get(walletUrl);

        expect(res.status()).toBe(200);
        expect(new URL(res.url()).pathname).toBe(walletUrl);
    });

    test.describe("as a cashier (no wallet permission)", () => {
        test.use({ account: "worker-cashier" });

        test("is redirected away from the page", async ({ serverAs }) => {
            const api = await serverAs("worker-cashier");
            const res = await api.get(walletUrl, { headers: { Referer: new URL(`/domains/${"jollibee-corp"}/sales`, "http://techiko-pos.test").href } });

            expect(new URL(res.url()).pathname).not.toBe(walletUrl);
        });

        test("gets 403 from the ledger API", async ({ serverAs }) => {
            const api = await serverAs("worker-cashier");
            const { day } = dateWindow();

            const res = await apiJson(api, "POST", ledgerUrl(), {
                direction: "in",
                amount: 10,
                kind: "adjustment",
                movement_date: day(0),
            });

            expect(res.status).toBe(403);
        });

        test("doesn't see Cash & wallet in the sidebar", async ({ page }) => {
            await page.goto(`/domains/jollibee-corp/customers`);
            await expect(sidebarItem(page, "Customers")).toBeVisible();

            await expect(
                page.getByRole("complementary").getByText("Cash & wallet", { exact: true }),
                "a menu group with no permitted pages should be hidden",
            ).toHaveCount(0);
        });
    });

    test("another organization's manager is denied", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        const page = await apiJson(api, "GET", walletUrl);
        expect(page.status).toBe(403);

        const post = await apiJson(api, "POST", ledgerUrl(), {
            direction: "in",
            amount: 10,
            kind: "adjustment",
            movement_date: dateWindow().day(0),
        });
        expect(post.status).toBe(403);
    });
});
