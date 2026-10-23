import { test, expect, apiJson } from "../support/fixtures.js";
import {
    DrawerPage,
    balancesUrl,
    cashInOutUrl,
    dateWindow,
    drawerUrl,
    futureYmd,
    historyUrl,
    ledgerUrl,
    walletFixture,
    walletUrl,
} from "../support/wallet.js";
import { pageProps } from "../support/inertia.js";

const sidebarItem = (page, title) =>
    page.getByRole("complementary").getByRole("menuitem", { name: new RegExp(`^${title}(\\s|$)`) });

test.describe("Cash & wallet access", () => {
    test("guests are sent to login", async ({ page }) => {
        await page.goto(drawerUrl);
        await expect(page).toHaveURL(/\/login$/);
    });

    test.describe("as a manager", () => {
        test.use({ account: "wallet-manager" });

        test("opens Today's drawer for their own store", async ({ page }, testInfo) => {
            await DrawerPage.open(page);

            await expect(page.getByRole("main").getByText("Today's drawer", { exact: true }).first()).toBeVisible();
            await expect(page.getByTestId("open-end-shift")).toBeVisible();

            const props = await pageProps(page);
            expect(props.activeLocation.id).toBe(walletFixture(testInfo).locationId);
        });

        test("the four pages are tabs that keep the day being looked at", async ({ page }) => {
            const { day } = dateWindow();
            await DrawerPage.open(page, day(0));

            const tabs = page.getByTestId("wallet-tabs");
            for (const key of ["drawer", "cash-in-out", "balances", "history"]) {
                await expect(tabs.getByTestId(`wallet-tab-${key}`)).toBeVisible();
            }
            await tabs.getByTestId("wallet-tab-history").click();
            await expect(page).toHaveURL(new RegExp(`${historyUrl}\\?.*business_date=${day(0)}`));
            await expect(page.getByTestId("history-counts")).toContainText(day(0));
        });

        test("the sidebar lists the four pages", async ({ page }) => {
            await DrawerPage.open(page);

            const sidebar = page.getByRole("complementary");
            await sidebar.getByText("Cash & wallet", { exact: true }).click();
            for (const title of ["Today's drawer", "Cash in & out", "Where money is", "Drawer history"]) {
                await expect(sidebar.getByText(title, { exact: true })).toBeVisible();
            }
        });

        test("defaults the business date to today", async ({ page }) => {
            await DrawerPage.open(page);

            const props = await pageProps(page);
            expect(props.cashControl.business_date).toBe(new Date().toISOString().slice(0, 10));
        });

        test("a future business date is rejected", async ({ serverAs }) => {
            const api = await serverAs("wallet-manager");

            const res = await apiJson(api, "GET", `${drawerUrl}?business_date=${futureYmd()}`);

            expect(res.status).toBe(422);
            expect(Object.keys(res.body.errors)).toContain("business_date");
        });

        test("can't view another store by passing its location_id", async ({ page }, testInfo) => {
            const others = Object.values(
                (await import("../support/sales.js")).fixtureIds().wallet.workers,
            ).filter((w) => w.locationId !== walletFixture(testInfo).locationId);

            await page.goto(`${drawerUrl}?location_id=${others[0].locationId}`);

            const props = await pageProps(page);
            expect(props.activeLocation.id, "a manager is restricted to their assigned store").toBe(walletFixture(testInfo).locationId);
        });

        test("old Money movement links land on the page for that job", async ({ page }) => {
            const { day } = dateWindow();

            await page.goto(`${walletUrl}?business_date=${day(0)}`);
            await expect(page).toHaveURL(new RegExp(`${drawerUrl}\\?.*business_date=${day(0)}`));

            await page.goto(`${walletUrl}?date_from=${day(0)}&date_to=${day(0)}`);
            await expect(page).toHaveURL(new RegExp(cashInOutUrl));

            await page.goto(ledgerUrl());
            await expect(page).toHaveURL(new RegExp(cashInOutUrl));

            await page.goto(`${walletUrl}?view=all`);
            await expect(page).toHaveURL(new RegExp(`${balancesUrl}\\?.*tab=all`));
        });
    });

    test("an admin can open every page", async ({ serverAs }) => {
        const api = await serverAs("admin");
        for (const url of [drawerUrl, cashInOutUrl, balancesUrl, historyUrl]) {
            const res = await api.get(url);
            expect(res.status(), url).toBe(200);
            expect(new URL(res.url()).pathname).toBe(url);
        }
    });

    test.describe("as a cashier (no wallet permission)", () => {
        test.use({ account: "worker-cashier" });

        test("is sent away from each page", async ({ serverAs }) => {
            const api = await serverAs("worker-cashier");
            const referer = new URL(`/domains/jollibee-corp/sales`, "http://techiko-pos.test").href;
            for (const url of [drawerUrl, cashInOutUrl, balancesUrl, historyUrl]) {
                const res = await api.get(url, { headers: { Referer: referer } });
                expect(new URL(res.url()).pathname, url).not.toBe(url);
            }
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

        const page = await apiJson(api, "GET", drawerUrl);
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
