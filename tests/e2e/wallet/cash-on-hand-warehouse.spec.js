import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps } from "../support/inertia.js";
import { pickSelectOption } from "../support/antd.js";
import { numberInput } from "../support/wallet.js";

/**
 * A warehouse's cash fund (jollibee-corp's seeded warehouse): the owner moves cash from Cash on
 * hand to the warehouse, sees it on the warehouse's Cash & wallet page, then moves part of it
 * back. Balances carry over between runs, so the checks compare with what was there before.
 */
test.describe.configure({ mode: "serial" });
test.use({ account: "admin" });

const domain = "jollibee-corp";
const url = (path) => `/domains/${domain}${path}`;
const posted = (page) =>
    page.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname === url("/wallet/cash-on-hand"));

test("the owner moves cash to a warehouse's cash fund and back", async ({ page, serverAs }) => {
    // Enough in Cash on hand for this run.
    const owner = await serverAs("admin");
    const added = await apiJson(owner, "POST", url("/wallet/cash-on-hand"), { action: "adjustment", direction: "in", amount: 1000, notes: "E2E warehouse fund" });
    expect(added.status, JSON.stringify(added.body)).toBeLessThan(400);

    await page.goto(url("/wallet/cash-on-hand"));
    const props = await pageProps(page);
    const warehouse = props.stores.find((s) => s.is_warehouse);
    expect(warehouse, "jollibee-corp has a warehouse").toBeTruthy();
    const fundBefore = props.warehouses.find((w) => w.id === warehouse.id)?.balance ?? 0;

    // Move ₱600 to the warehouse: no channel to pick, a hint says where it goes.
    await page.getByTestId("cash-on-hand-to-store").click();
    const move = page.locator(".ant-modal").filter({ hasText: "Move to a store or warehouse" });
    await pickSelectOption(page, move, 0, `${warehouse.name} · cash fund`);
    await expect(move.getByTestId("cash-on-hand-warehouse-hint")).toBeVisible();
    await expect(move.getByTestId("cash-on-hand-place")).toHaveCount(0);
    await numberInput(move, "cash-on-hand-amount").fill("600");
    const moved = posted(page);
    await move.getByTestId("cash-on-hand-save").click();
    expect((await moved).status()).toBeLessThan(400);
    await expect(move).toBeHidden();
    await expect(page.getByTestId(`warehouse-fund-${warehouse.id}`)).toBeVisible();

    // The warehouse's Cash & wallet page shows its fund and the line.
    await page.goto(url(`/wallet/drawer?location_id=${warehouse.id}`));
    const fund = page.getByTestId("warehouse-fund");
    await expect(fund).toBeVisible();
    expect((await pageProps(page)).warehouseFund.balance).toBe(fundBefore + 600);
    await expect(fund.getByTestId("warehouse-fund-lines")).toContainText("From Cash on hand");

    // ₱250 back to Cash on hand.
    await page.goto(url("/wallet/cash-on-hand"));
    await page.getByTestId("cash-on-hand-from-warehouse").click();
    const back = page.locator(".ant-modal").filter({ hasText: "Back to Cash on hand" });
    await pickSelectOption(page, back, 0, warehouse.name);
    await numberInput(back, "cash-on-hand-amount").fill("250");
    const returned = posted(page);
    await back.getByTestId("cash-on-hand-save").click();
    expect((await returned).status()).toBeLessThan(400);
    await expect(back).toBeHidden();
    await expect(page.getByTestId("cash-on-hand-lines")).toContainText("Back from a warehouse");

    await page.reload();
    expect((await pageProps(page)).warehouses.find((w) => w.id === warehouse.id).balance).toBe(fundBefore + 350);
});
