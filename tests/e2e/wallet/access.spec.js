import { test, expect, apiJson } from "../support/fixtures.js";
import {
    DrawerPage,
    balancesUrl,
    cashInOutUrl,
    dateWindow,
    drawerUrl,
    futureYmd,
    historyUrl,
    shiftsUrl,
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

        test("the pages are tabs that keep the day being looked at", async ({ page }) => {
            const { day } = dateWindow();
            await DrawerPage.open(page, day(0));

            const tabs = page.getByTestId("wallet-tabs");
            for (const key of ["drawer", "balances", "cash-in-out", "counts"]) {
                await expect(tabs.getByTestId(`wallet-tab-${key}`)).toBeVisible();
            }
            // Drawer history and Counted money are one page now.
            await expect(tabs.getByTestId("wallet-tab-history")).toHaveCount(0);
            await tabs.getByTestId("wallet-tab-counts").click();
            await expect(page).toHaveURL(new RegExp(`${shiftsUrl}\\?.*business_date=${day(0)}`));
            await page.getByTestId("counts-view-store").click();
            await expect(page.getByTestId("history-counts")).toContainText(day(0));
        });

        test("the sidebar has one Cash & wallet entry that opens Today's drawer", async ({ page }) => {
            await page.goto(`/domains/jollibee-corp/customers`);

            const sidebar = page.getByRole("complementary");
            await sidebar.getByText("Cash & wallet", { exact: true }).click();
            await expect(page).toHaveURL(new RegExp(drawerUrl));
            // Its pages are the tabs, not sidebar items.
            for (const title of ["Cash in & out", "Where money is", "Drawer history", "Counted money", "Shifts & counts"]) {
                await expect(sidebar.getByText(title, { exact: true }), title).toHaveCount(0);
            }
        });

        test("Drawer history's old links land on Shifts & counts' store counts", async ({ page }) => {
            const { day } = dateWindow();
            await page.goto(`${historyUrl}?business_date=${day(0)}`);
            await expect(page).toHaveURL(new RegExp(`${shiftsUrl}\\?.*view=store`));
            await expect(page.getByTestId("history-counts")).toContainText(day(0));
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
            await expect(page).toHaveURL(new RegExp(`${cashInOutUrl}\\?.*view=all`));
        });
    });

    test("an admin can open every page", async ({ serverAs }) => {
        const api = await serverAs("admin");
        for (const url of [drawerUrl, cashInOutUrl, balancesUrl, shiftsUrl]) {
            const res = await api.get(url);
            expect(res.status(), url).toBe(200);
            expect(new URL(res.url()).pathname).toBe(url);
        }
    });

    test.describe("as a cashier (Today's drawer only)", () => {
        test.use({ account: "worker-cashier" });

        test("opens Today's drawer and is sent away from the other wallet pages", async ({ serverAs }) => {
            const api = await serverAs("worker-cashier");
            const referer = new URL(`/domains/jollibee-corp/sales`, "http://techiko-pos.test").href;
            const drawer = await api.get(drawerUrl, { headers: { Referer: referer } });
            expect(new URL(drawer.url()).pathname).toBe(drawerUrl);
            for (const url of [cashInOutUrl, balancesUrl, shiftsUrl]) {
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

        test("Cash & wallet opens Today's drawer, with no other tabs", async ({ page }) => {
            await page.goto(`/domains/jollibee-corp/customers`);
            await expect(sidebarItem(page, "Customers")).toBeVisible();

            const sidebar = page.getByRole("complementary");
            await sidebar.getByText("Cash & wallet", { exact: true }).click();
            await expect(page).toHaveURL(new RegExp(drawerUrl));
            // Today's drawer is their only page, so there are no tabs to pick from.
            await expect(page.getByTestId("wallet-tabs")).toHaveCount(0);
        });

        test("sees their own shift, not the store's drawer or whether it came out short or over", async ({ page }) => {
            await page.goto(drawerUrl);
            await expect(page.getByTestId("my-shift")).toBeVisible();
            // Each cashier has their own tray: they count it when ending their shift.
            await expect(page.getByTestId("drawer-expected")).toHaveCount(0);
            await expect(page.getByTestId("open-count-cash")).toHaveCount(0);
            await expect(page.getByTestId("drawer-result")).toHaveCount(0);
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
