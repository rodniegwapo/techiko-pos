import { test, expect, apiJson } from "../support/fixtures.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { fixtureIds } from "../support/sales.js";

const fixture = () => fixtureIds().inventoryDashboard;
const orgUrl = (domain, path = "") => `/domains/${domain}${path}`;
const jb = (path = "") => orgUrl("jollibee-corp", path);
const switchUrl = (locationId, domain = "jollibee-corp") => orgUrl(domain, `/inventory/locations/${locationId}/switch`);

/** Shared Inertia props of the inventory dashboard for an API session. */
const dashboardProps = async (api, domain = "jollibee-corp") => responseProps(await api.get(orgUrl(domain, "/inventory")));

test.describe("Switching stores with the location badge (admin)", () => {
    test.use({ account: "admin" });

    test("switches this admin's store without changing the organization's default store", async ({ page, serverAs }) => {
        const { mainLocation, branchLocation } = fixture();
        await page.goto(jb("/inventory"));

        await page.locator("div.fixed.top-4.right-4").getByRole("button").click();
        const popover = page.locator(".ant-popover:not(.ant-popover-hidden)").filter({ hasText: "Switch Location" });
        await popover.getByText(branchLocation.name, { exact: true }).click();

        await expect(page).toHaveURL((url) => url.searchParams.get("location_id") === String(branchLocation.id));
        await expect(page.getByRole("main").getByText(branchLocation.name, { exact: true })).toBeVisible();

        // Everyone else still works in the organization's default store.
        const otherAdmin = await serverAs("admin");
        const props = await dashboardProps(otherAdmin);
        expect(props.default_store.code, "organization default store").toBe(mainLocation.code);
        expect(props.report.location.code, "another admin's store").toBe(mainLocation.code);
    });

    test("the switched store follows the admin to other pages", async ({ page }) => {
        const { branchLocation } = fixture();
        await page.goto(jb("/inventory"));
        await page.locator("div.fixed.top-4.right-4").getByRole("button").click();
        await page.locator(".ant-popover:not(.ant-popover-hidden)").getByText(branchLocation.name, { exact: true }).click();
        await expect(page).toHaveURL((url) => url.searchParams.get("location_id") === String(branchLocation.id));

        await page.goto(jb("/inventory"));
        expect((await pageProps(page)).report.location.code, "dashboard without ?location_id").toBe(branchLocation.code);

        await page.goto(jb("/sales"));
        expect((await pageProps(page)).currentLocation.code, "sales page").toBe(branchLocation.code);
    });
});

test.describe("Store switching API", () => {
    test("an admin's switch only affects their own session", async ({ serverAs }) => {
        const { mainLocation, branchLocation } = fixture();
        const admin = await serverAs("admin");
        const otherAdmin = await serverAs("admin");

        const res = await apiJson(admin, "POST", switchUrl(branchLocation.id), {});

        expect(res.status).toBe(200);
        expect((await dashboardProps(admin)).report.location.code).toBe(branchLocation.code);
        expect((await dashboardProps(otherAdmin)).report.location.code).toBe(mainLocation.code);
        expect((await dashboardProps(otherAdmin)).default_store.code).toBe(mainLocation.code);
    });

    test("can't switch to another organization's store", async ({ serverAs }) => {
        const admin = await serverAs("admin");

        const res = await apiJson(admin, "POST", switchUrl(fixture().otherOrgLocationId), {});

        expect([403, 404]).toContain(res.status);
        expect((await dashboardProps(admin)).report.location.domain).toBe("jollibee-corp");
    });

    test("can't switch to an inactive store", async ({ serverAs }) => {
        const admin = await serverAs("admin");

        const res = await apiJson(admin, "POST", switchUrl(fixture().store.id), {});

        expect(res.status).toBe(422);
    });

    for (const account of ["manager", "cashier"]) {
        test(`a ${account} assigned to a store can't switch`, async ({ serverAs }) => {
            const api = await serverAs(account);

            const res = await apiJson(api, "POST", switchUrl(fixture().branchLocation.id), {});

            expect(res.status).toBe(403);
            expect((await dashboardProps(api)).report.location.code).toBe(fixture().mainLocation.code);
        });
    }
});

test.describe("Default store", () => {
    test("making the current default store the default again keeps it the default", async ({ serverAs }) => {
        const { mainLocation } = fixture();
        const admin = await serverAs("admin");

        const res = await apiJson(admin, "POST", jb(`/inventory/locations/${mainLocation.id}/set-default`), {});

        expect(res.status).toBeLessThan(400);
        expect((await dashboardProps(admin)).default_store?.code, "Jollibee still has a default store").toBe(mainLocation.code);
    });

    for (const [label, url] of [
        ["the organization route", (id) => jb(`/inventory/locations/${id}/set-default`)],
        ["the route outside organizations", (id) => `/inventory/locations/${id}/set-default`],
        ["the API", (id) => `/api/inventory/locations/${id}/set-default`],
    ]) {
        test(`a Jollibee admin can't change McDonald's default store through ${label}`, async ({ serverAs }) => {
            const admin = await serverAs("admin");

            // McDonald's main store is already its default, so even a wrongly accepted call can only
            // show up as McDonald's losing its default.
            const res = await apiJson(admin, "POST", url(fixture().otherOrgLocationId), {});

            expect([401, 403, 404]).toContain(res.status);
            const mc = await serverAs("mcManager");
            expect((await dashboardProps(mc, "mcdonalds-corp")).default_store?.code, "McDonald's default store").toBe("MC-MAIN");
        });
    }

    test("a Jollibee admin can't deactivate a McDonald's store", async ({ serverAs }) => {
        const admin = await serverAs("admin");
        const { otherOrgLocationId } = fixture();

        const res = await apiJson(admin, "POST", `/inventory/locations/${otherOrgLocationId}/toggle-status`, {});
        if (res.status < 400) {
            // Put it back before failing, so McDonald's keeps an active store.
            await apiJson(admin, "POST", `/inventory/locations/${otherOrgLocationId}/toggle-status`, {});
        }

        expect([403, 404]).toContain(res.status);
    });

    test("a Jollibee admin's store list outside organizations only has Jollibee stores", async ({ serverAs }) => {
        const admin = await serverAs("admin");

        const res = await apiJson(admin, "GET", "/inventory/locations?per_page=100");

        expect(res.status).toBe(200);
        const domains = new Set(res.body.data.map((l) => l.domain));
        expect([...domains]).toEqual(["jollibee-corp"]);
    });
});
