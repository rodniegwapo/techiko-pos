import { test, expect, apiJson } from "../support/fixtures.js";
import { fixtureIds } from "../support/sales.js";

/**
 * Charging a customer on credit by hand, split into installments, and paying part of it: the
 * earliest installment is paid first, and the charge stays open until all of it is paid.
 *
 * Each Playwright worker has its own account owing nothing ("E2E Cred Installments n" from
 * E2ECreditSeeder), so the charges made here can't disturb the other credit tests.
 */

const creditsPath = "/domains/jollibee-corp/credits";
const customerUrl = (id, path = "") => `${creditsPath}/customers/${id}${path}`;
const account = (parallelIndex) => {
    const accounts = fixtureIds().credit.installments;
    return accounts[parallelIndex % accounts.length];
};

async function charges(api, id) {
    const res = await apiJson(api, "GET", customerUrl(id, "/outstanding-invoices"));
    expect(res.status).toBe(200);
    return res.body.outstanding_invoices;
}

test.describe("Manual credit in installments (admin)", () => {
    test.use({ account: "admin" });

    test("a charge in 3 monthly installments, then a part payment", async ({ page, serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const customer = account(testInfo.parallelIndex);
        const before = await charges(api, customer.id);

        await page.goto(customerUrl(customer.id));
        await page.getByRole("button", { name: "Add Transaction" }).click();
        const dialog = page.getByRole("dialog").filter({ hasText: "Add Credit Transaction" });
        await expect(dialog).toContainText("Charge (manual credit)");

        await dialog.locator(".ant-input-number-input").first().fill("300");
        await dialog.getByText("Pay in installments").click();
        // The default plan: 3 payments a month apart, splitting the amount evenly.
        await expect(dialog.locator(".ant-table-tbody tr.ant-table-row")).toHaveCount(3);
        await expect(dialog).toContainText("Schedule total ₱300.00 of ₱300.00");

        const saved = page.waitForRequest((r) => r.method() === "POST" && r.url().includes("/transactions"));
        await dialog.getByRole("button", { name: "OK" }).click();
        const body = (await saved).postDataJSON();
        expect(body.transaction_type).toBe("credit");
        expect(body.installments.map((i) => i.amount)).toEqual([100, 100, 100]);
        await expect(dialog).toBeHidden();

        const after = await charges(api, customer.id);
        expect(after.length).toBe(before.length + 1);
        const charge = after.find((c) => !before.some((b) => b.id === c.id));
        expect(charge.installments).toHaveLength(3);
        await expect(page.getByText("3 installments").first()).toBeVisible();

        // ₱150 pays the first installment and half of the second.
        const paid = await apiJson(api, "POST", customerUrl(customer.id, "/transactions"), {
            transaction_type: "payment",
            amount: 150,
            payment_method: "cash",
            transaction_ids: [charge.id],
        });
        expect(paid.status).toBe(200);

        const now = (await charges(api, customer.id)).find((c) => c.id === charge.id);
        expect(now, "still owed, not marked paid").toBeTruthy();
        expect(Number(now.remaining)).toBe(150);
        expect(now.installments[0].paid_at).toBeTruthy();
        expect(Number(now.installments[1].paid_amount)).toBe(50);
        expect(now.installments[1].paid_at).toBeNull();

        // The page shows what is left and the schedule.
        await page.reload();
        const row = page.locator(".ant-table-row").filter({ hasText: "3 installments" }).first();
        await expect(row).toContainText("₱150.00");
        await row.locator(".ant-table-row-expand-icon").click();
        const schedule = page.locator(".ant-table-expanded-row").first();
        await expect(schedule).toContainText("Paid");
        await expect(schedule).toContainText("Partly paid");

        // Settle the rest, so the account owes nothing for the next run of this worker.
        const rest = await apiJson(api, "POST", customerUrl(customer.id, "/transactions"), {
            transaction_type: "payment",
            amount: 150,
            transaction_ids: [charge.id],
        });
        expect(rest.status).toBe(200);
        expect((await charges(api, customer.id)).some((c) => c.id === charge.id)).toBe(false);
    });

    test("installments that don't add up are refused", async ({ serverAs }, testInfo) => {
        const api = await serverAs("admin");
        const customer = account(testInfo.parallelIndex);
        const due = (months) => {
            const d = new Date();
            d.setMonth(d.getMonth() + months);
            return d.toISOString().slice(0, 10);
        };

        const res = await apiJson(api, "POST", customerUrl(customer.id, "/transactions"), {
            transaction_type: "credit",
            amount: 300,
            installments: [
                { due_date: due(1), amount: 100 },
                { due_date: due(2), amount: 100 },
            ],
        });

        expect(res.status).toBe(422);
        expect(Object.keys(res.body.errors ?? {})).toContain("installments");
    });
});

test.describe("Manual credit (cashier)", () => {
    test("a cashier can't charge credit by hand", async ({ serverAs }) => {
        const api = await serverAs("cashier");
        const customer = account(0);

        const res = await apiJson(api, "POST", customerUrl(customer.id, "/transactions"), {
            transaction_type: "credit",
            amount: 100,
            due_date: new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10),
        });

        expect(res.status).toBe(403);
    });
});
