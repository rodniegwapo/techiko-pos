import { test as base, expect } from "@playwright/test";
import { USERS } from "./users.js";
import { login } from "./inertia.js";

/** Requests slower than this (ms) are listed in the findings report. */
const SLOW_MS = Number(process.env.E2E_SLOW_MS) || 3000;

/** Header the suite's own probe requests carry, so their expected 403s aren't reported as unusual. */
export const PROBE_HEADER = "x-e2e-probe";

/**
 * - `test.use({ account: "cashier" })` signs the browser page in before each test.
 * - `serverAs("cashier")` returns an HTTP-only session (no browser, no app JavaScript).
 * - `observe("message")` records something odd that shouldn't fail the test; it shows
 *   up in tests/e2e/findings-report.md.
 *
 * Why the HTTP-only session: sessions are file-based without locking, so a request the
 * app fires in the background (charts, polling) can finish after a denied request and
 * write the session back without its one-time flash message. Use `serverAs` to assert
 * flash messages. Each call and each test logs in on its own so nothing shares a session.
 *
 * Every browser page also records console errors, JS exceptions, failed/4xx/5xx and slow
 * requests for the findings report (see support/findings-reporter.js).
 */
export const test = base.extend({
    account: [null, { option: true }],

    page: async ({ page, account }, use, testInfo) => {
        const findings = recordFindings(page);

        if (account) {
            await login(page, USERS[account]);
        }
        await use(page);

        if (findings.length) {
            await testInfo.attach("findings", {
                body: JSON.stringify(findings),
                contentType: "application/json",
            });
        }
    },

    serverAs: async ({ playwright, baseURL }, use) => {
        const contexts = [];

        await use(async (account) => {
            const api = await playwright.request.newContext({ baseURL });
            contexts.push(api);
            await apiLogin(api, USERS[account]);
            return api;
        });

        await Promise.all(contexts.map((c) => c.dispose()));
    },

    observe: async ({}, use, testInfo) => {
        await use((description) => {
            testInfo.annotations.push({ type: "observation", description });
        });
    },
});

function recordFindings(page) {
    const findings = [];
    const add = (kind, detail, extra = {}) => findings.push({ kind, detail, ...extra });
    const describe = (request) => `${request.method()} ${new URL(request.url()).pathname}`;
    const isAppRequest = (request) => ["document", "xhr", "fetch"].includes(request.resourceType());

    page.on("console", (msg) => {
        // HTTP failures are reported from the response itself, where the suite's own probes are filtered out.
        if (msg.text().startsWith("Failed to load resource")) return;
        if (msg.type() === "error") add("console-error", msg.text().slice(0, 300));
        if (msg.type() === "warning") add("console-warning", msg.text().slice(0, 300));
    });

    page.on("pageerror", (error) => add("js-exception", error.message.slice(0, 300)));

    page.on("response", async (response) => {
        const request = response.request();
        const status = response.status();
        if (status < 400 || !isAppRequest(request)) return;
        if ((await request.allHeaders())[PROBE_HEADER]) return;

        add(status >= 500 ? "server-error" : "client-error", `${status} ${describe(request)}`);
    });

    page.on("requestfailed", (request) => {
        const reason = request.failure()?.errorText ?? "unknown";
        // Navigations cancel in-flight requests; that's normal.
        if (reason.includes("ERR_ABORTED")) return;
        add("request-failed", `${describe(request)} (${reason})`);
    });

    page.on("requestfinished", (request) => {
        if (!isAppRequest(request)) return;
        const ms = Math.round(request.timing().responseEnd);
        if (ms >= SLOW_MS) add("slow-request", describe(request), { ms });
    });

    return findings;
}

/** Posts the login form over HTTP with Laravel's XSRF cookie. */
async function apiLogin(api, { email, password }) {
    await api.get("/login");
    const xsrf = (await api.storageState()).cookies.find((c) => c.name === "XSRF-TOKEN");

    const res = await api.post("/login", {
        form: { email, password },
        headers: { "X-XSRF-TOKEN": decodeURIComponent(xsrf.value), Accept: "text/html" },
        maxRedirects: 0,
        timeout: 30_000,
    });

    expect(res.status(), `login as ${email}`).toBe(302);
    expect(res.headers().location, `login as ${email}`).not.toMatch(/\/login$/);
}

export { expect };
