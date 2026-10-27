import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps } from "../support/inertia.js";
import { USERS } from "../support/users.js";
import { numberInput } from "../support/wallet.js";

const DOMAIN = USERS.admin.domain;
const url = (path) => `/domains/${DOMAIN}${path}`;

// Receiving a purchase order can pay the supplier there and then, from a store's cash drawer.
test.describe("Receiving a purchase order with Pay now as admin", () => {
    test.use({ account: "admin" });

    test("pays part of the delivery from the store's drawer and leaves the rest on the bill", async ({ page, serverAs }) => {
        const api = await serverAs("admin");
        const supplier = `E2E PayNow Supplier ${Date.now()}`;

        await page.goto(url("/purchasing"));
        const props = await pageProps(page);
        expect(props.canPayNow, "admin may pay bills").toBe(true);
        const store = props.drawerLocations[0];
        expect(store, "a store with a drawer").toBeTruthy();
        expect(props.drawerLocations.every((l) => l.type !== "warehouse"), "warehouses have no drawer").toBe(true);

        // The order form asks how it will be paid; cash asks which store's drawer.
        await page.getByTestId("new-po").click();
        const payFrom = page.getByTestId("po-pay-from");
        await expect(payFrom).toContainText("Pay from (optional)");
        await payFrom.getByTestId("payment-method").click();
        await page.locator(".ant-select-dropdown:visible .ant-select-item-option", { hasText: "Cash on hand" }).click();
        await expect(payFrom.getByTestId("cash-drawer")).toBeVisible();
        await page.locator(".ant-modal:visible").getByRole("button", { name: "Close", exact: true }).last().click();

        const products = await apiJson(api, "GET", `${url("/purchasing")}?product_search=a`);
        const product = products.body.data[0];
        const sent = await apiJson(api, "POST", url("/purchasing"), {
            supplier_name: supplier,
            location_id: store.id,
            send: true,
            lines: [{ product_id: product.id, quantity_ordered: 2, unit_cost: 50 }],
            // Pay from that store's drawer, chosen on the order; nothing is paid until it arrives.
            pay_method: "cash_register",
            pay_location_id: store.id,
        });
        expect(sent.status).toBeLessThan(400);

        await page.goto(url(`/purchasing?search=${encodeURIComponent(supplier)}`));
        await page.locator("tr", { hasText: supplier }).getByTestId("receive-po").click();
        const dialog = page.locator(".ant-modal:visible", { hasText: "Receive" });
        await expect(dialog).toContainText("This delivery is worth ₱100.00");

        await expect(dialog.getByTestId("receive-pay-now"), "on, from the order's Pay from").toHaveAttribute("aria-checked", "true");
        const amount = numberInput(dialog, "receive-pay-amount");
        await expect(amount, "defaults to the delivery's value").toHaveValue("100.00");
        await amount.fill("40");
        await expect(dialog.getByTestId("cash-drawer")).toContainText(store.name);
        await dialog.getByRole("button", { name: "Receive and pay" }).click();

        await expect(dialog).toHaveCount(0);
        await expect(page.locator("tr", { hasText: supplier })).toContainText("Received");

        // The bill is partly paid (data-page holds the first load's props, so reload).
        await page.reload();
        const order = (await pageProps(page)).orders.data.find((o) => o.supplier?.name === supplier);
        expect(Number(order.bill.amount)).toBe(100);
        expect(Number(order.bill.paid_amount)).toBe(40);

        // The cash left that store's drawer.
        await page.goto(url(`/wallet/cash-in-out?location_id=${store.id}&kind=supplier_payment`));
        await expect(
            page.locator("[data-testid=cash-lines] tr.ant-table-row", { hasText: supplier }).first(),
        ).toContainText("− ₱40.00");
    });
});
