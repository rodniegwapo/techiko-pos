import { test, expect, apiJson } from "../support/fixtures.js";
import { notice } from "../support/antd.js";
import { pageProps, responseProps } from "../support/inertia.js";
import { USERS } from "../support/users.js";

/**
 * The user hierarchy page (User Hierarchy), reached from the users list.
 *
 * It draws the organization's reporting line as an org chart, counts the people in it, lists
 * everyone who reports to nobody, and offers to assign supervisors automatically.
 *
 * Nobody reports to anybody when a run starts — E2EUserSeeder clears the organization's
 * supervisors — so the chart begins as the viewer alone and everybody is listed as unassigned.
 * A test that changes who reports to whom puts it back in the afterEach; the reading tests take
 * their expectations from the page load in front of them rather than from fixed numbers, so one
 * of them running while another is assigning cannot make it fail.
 *
 * Only an admin may open the page: RolePermissionSeeder grants users.hierarchy to that role
 * alone, so even a supervisor, who may read the users list, is refused here.
 */

const hierarchyPath = "/domains/jollibee-corp/users/hierarchy";
const usersPath = "/domains/jollibee-corp/users";
const PASSWORD = "e2e-password";
/** Role ids from Roleseeder: 1 super admin, 2 admin, 3 manager, 4 supervisor, 5 cashier. */
const MANAGER_ROLE_ID = 3;

const statCard = (page, label) => page.locator(".stat-card").filter({ hasText: label });
const chart = (page) => page.locator("#d3-org-chart");
const chartNodes = (page) => chart(page).locator(".node");
const nodeFor = (page, text) => chartNodes(page).filter({ hasText: text });
/** The cards under "Users Without Supervisors". */
const unassignedCards = (page) => page.locator("h4.font-medium.text-gray-900");

/** Users created by the test that is running; removed again in afterEach. */
const created = [];
/** Users the test gave a supervisor to; set back to reporting to nobody in afterEach. */
const assigned = [];

const uniqueEmail = () => `e2e-hier-${Math.random().toString(36).slice(2, 8)}@techiko.test`;

const figure = async (page, label) =>
    Number((await statCard(page, label).innerText()).split("\n")[0].replace(/,/g, ""));

async function openHierarchy(page) {
    await page.goto(hierarchyPath);
    await expect(page.getByRole("heading", { name: "User Hierarchy" })).toBeVisible();
    // The chart is drawn by d3 once the page has its data.
    await expect(chartNodes(page).first()).toBeVisible();
}

/** The people the page was given, read from the render in front of us. */
const peopleOn = async (page) => (await pageProps(page)).users;

/** The same, fetched fresh, for reading the hierarchy back after it has been changed. */
const peopleNow = async (api) => (await responseProps(await api.get(hierarchyPath))).users;

/** Adds a user to the organization and has them report to `supervisorId`. */
async function addReport(api, { name, role = MANAGER_ROLE_ID, supervisorId }) {
    const email = uniqueEmail();
    const made = await apiJson(api, "POST", usersPath, {
        name,
        email,
        password: PASSWORD,
        password_confirmation: PASSWORD,
        role_id: role,
        supervisor_id: supervisorId,
    });
    expect(made.status, `create ${name}: ${JSON.stringify(made.body)?.slice(0, 200)}`).toBe(201);

    const user = made.body.user.data ?? made.body.user;
    created.push(user.id);

    return { ...user, email };
}

/** The organization's admin, as the hierarchy page has them. */
async function adminOn(page) {
    const admin = (await peopleOn(page)).find((u) => u.email === USERS.admin.email);
    expect(admin, "the organization's admin is listed").toBeTruthy();

    return admin;
}

test.afterEach(async ({ serverAs }) => {
    if (! created.length && ! assigned.length) {
        return;
    }
    const superUser = await serverAs("super");

    // Put the reporting line back before removing anything, so a user is never left pointing at
    // a supervisor that has just been deleted.
    while (assigned.length) {
        await apiJson(superUser, "DELETE", `/users/${assigned.pop()}/remove-supervisor`);
    }
    while (created.length) {
        await apiJson(superUser, "DELETE", `/api/users/${created.pop()}`);
    }
});

test.describe("The hierarchy page (admin)", () => {
    test.use({ account: "admin" });

    test("opens on the organization's reporting line", async ({ page }) => {
        await openHierarchy(page);

        await expect(page).toHaveTitle(/User Hierarchy/);
        await expect(page.getByText("Supervisor-subordinate organizational structure")).toBeVisible();
        await expect(page.getByRole("heading", { name: "Organizational Structure" })).toBeVisible();
    });

    test("the four figures describe the people it is showing", async ({ page }) => {
        await openHierarchy(page);
        const people = await peopleOn(page);

        expect(await figure(page, "Total Users")).toBe(people.length);
        expect(await figure(page, "Active Users")).toBe(
            people.filter((u) => u.status === "active").length,
        );
        expect(await figure(page, "Without Supervisor"), "nobody above them").toBe(
            people.filter((u) => ! u.is_super_user && ! u.supervisor_id).length,
        );
        expect(await figure(page, "Top Level")).toBeGreaterThan(0);
    });

    test("the chart draws the viewer at the top of their own line", async ({ page }) => {
        await openHierarchy(page);
        const admin = await adminOn(page);

        const me = nodeFor(page, admin.email);
        await expect(me).toHaveCount(1);
        await expect(me, "named, with their rank and address").toContainText(admin.name);
        await expect(me).toContainText("admin");
        await expect(me, "and marked as the person looking at it").toContainText("YOU");
    });

    test("somebody who reports to the admin is drawn under them", async ({ page, serverAs }) => {
        const api = await serverAs("admin");
        await openHierarchy(page);
        const admin = await adminOn(page);

        const report = await addReport(api, { name: "E2E Reports To Admin", supervisorId: admin.id });
        assigned.push(report.id);

        await openHierarchy(page);

        await expect(nodeFor(page, report.email), "on the chart now").toHaveCount(1);
        const people = await peopleOn(page);
        expect(people.find((u) => u.id === report.id).supervisor_id, "under the admin").toBe(admin.id);
        await expect(
            unassignedCards(page).filter({ hasText: "E2E Reports To Admin" }),
            "and no longer listed as reporting to nobody",
        ).toHaveCount(0);
    });

    test("a rung further down is folded away behind a count", async ({ page, serverAs }) => {
        const api = await serverAs("admin");
        await openHierarchy(page);
        const admin = await adminOn(page);

        const manager = await addReport(api, { name: "E2E Middle Manager", supervisorId: admin.id });
        assigned.push(manager.id);
        const supervisor = await addReport(api, {
            name: "E2E Their Supervisor",
            role: 4,
            supervisorId: manager.id,
        });
        assigned.push(supervisor.id);

        await openHierarchy(page);

        // The chart opens on the viewer's own direct reports. Whoever is below them is folded
        // away until the reader opens that node, so what has to be on screen is the manager and
        // a count saying somebody is underneath — not the person themselves.
        await expect(nodeFor(page, manager.email)).toHaveCount(1);
        await expect(nodeFor(page, supervisor.email), "a rung too far down to be drawn yet").toHaveCount(0);
        await expect(
            chart(page).locator(".node-button-foreign-object").filter({ hasText: "1" }).first(),
            "the folded rung is counted on the chart",
        ).toBeVisible();

        const people = await peopleOn(page);
        expect(
            people.find((u) => u.id === supervisor.id).supervisor_id,
            "and the page was given the whole line",
        ).toBe(manager.id);
    });

    test("everyone reporting to nobody is listed under the chart", async ({ page }) => {
        await openHierarchy(page);
        const people = await peopleOn(page);
        const unassigned = people.filter((u) => ! u.is_super_user && ! u.supervisor_id);

        await expect(page.getByRole("heading", { name: "Users Without Supervisors" })).toBeVisible();
        await expect(unassignedCards(page)).toHaveCount(unassigned.length);
        await expect(page.getByText(`${unassigned.length} users need supervisor assignment`)).toBeVisible();
        for (const person of unassigned.slice(0, 3)) {
            await expect(unassignedCards(page).filter({ hasText: person.name }).first()).toBeVisible();
        }
    });

    test("only this organization's people are on it", async ({ page }) => {
        await openHierarchy(page);

        const domains = new Set((await peopleOn(page)).map((u) => u.domain));
        expect([...domains]).toEqual(["jollibee-corp"]);
    });

    test("no password hashes are sent to the browser", async ({ page }) => {
        await openHierarchy(page);

        // The page is handed the user records as they come out of the database, so this is worth
        // holding to: a field added to the model would otherwise arrive here unnoticed.
        for (const person of await peopleOn(page)) {
            expect(Object.keys(person)).not.toContain("password");
            expect(Object.keys(person)).not.toContain("remember_token");
        }
    });

    test("Back returns to the users list", async ({ page }) => {
        await openHierarchy(page);

        await page.getByRole("button", { name: "Back" }).click();

        await expect(page).toHaveURL(new RegExp(`${usersPath}$`));
        await expect(page.locator(".ant-table")).toBeVisible();
    });
});

test.describe("Assigning supervisors automatically (admin)", () => {
    test.use({ account: "admin" });
    // This one moves the whole organization's reporting line, so it runs on its own.
    test.describe.configure({ mode: "default" });

    test("auto-assign gives people a supervisor and says what it did", async ({ page, serverAs }) => {
        await openHierarchy(page);
        const before = await peopleOn(page);
        const unassigned = before.filter((u) => ! u.is_super_user && ! u.supervisor_id);
        expect(unassigned.length, "there is something to assign").toBeGreaterThan(0);
        assigned.push(...unassigned.map((u) => u.id));

        await page.getByRole("button", { name: /Auto-Assign Supervisors/ }).click();

        // Whoever is left over is left over for a reason — their next rank up has nobody in it —
        // and the tally is the only place that is explained, so the button must not stay silent.
        const said = notice(page, "Supervisors assigned");
        await expect(said).toBeVisible();
        await expect(said).toContainText(/\d+ assigned, \d+ skipped, 0 errors/);

        const api = await serverAs("admin");
        const after = await peopleNow(api);
        const nowReporting = after.filter((u) => u.supervisor_id).length;
        expect(nowReporting, "more people report to somebody than before").toBeGreaterThan(
            before.filter((u) => u.supervisor_id).length,
        );

        // Nobody is their own supervisor, and nobody reports to somebody who isn't on the page.
        for (const person of after.filter((u) => u.supervisor_id)) {
            expect(person.supervisor_id, `${person.email} reports to themselves`).not.toBe(person.id);
            expect(person.supervisor, `${person.email}'s supervisor is named`).toBeTruthy();
        }
    });

    test("the chart grows once people report to somebody", async ({ page, serverAs }) => {
        const api = await serverAs("admin");
        await openHierarchy(page);
        const admin = await adminOn(page);
        const alone = await chartNodes(page).count();

        const report = await addReport(api, { name: "E2E Grows The Chart", supervisorId: admin.id });
        assigned.push(report.id);
        await openHierarchy(page);

        expect(await chartNodes(page).count()).toBe(alone + 1);
    });
});

test.describe("Hierarchy access (API)", () => {
    test("a cashier can't open the hierarchy", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        expect((await apiJson(api, "GET", hierarchyPath)).status).toBe(403);
    });

    test("a manager can't open the hierarchy", async ({ serverAs }) => {
        const api = await serverAs("manager");

        expect((await apiJson(api, "GET", hierarchyPath)).status).toBe(403);
    });

    test("another organization's manager can't read Jollibee's hierarchy", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        expect((await apiJson(api, "GET", hierarchyPath)).status).toBe(403);
    });

    test("another organization's manager can't set Jollibee's supervisors", async ({ serverAs }) => {
        const api = await serverAs("mcManager");

        const res = await apiJson(api, "POST", "/domains/jollibee-corp/supervisors/auto-assign");

        expect(res.status).toBe(403);
    });

    test("a cashier can't set supervisors", async ({ serverAs }) => {
        const api = await serverAs("cashier");

        const res = await apiJson(api, "POST", "/domains/jollibee-corp/supervisors/auto-assign");

        expect(res.status).toBe(403);
    });

    test("guests are sent to login", async ({ page }) => {
        await page.goto(hierarchyPath);

        await expect(page).toHaveURL(/\/login$/);
    });
});
