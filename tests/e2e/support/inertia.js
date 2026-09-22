import { expect } from "@playwright/test";

/**
 * Props of the Inertia page rendered by a full page load (`page.goto`).
 * Reads the `data-page` attribute Laravel writes into the root element.
 */
export async function pageProps(page) {
    const json = await page.locator("#app").getAttribute("data-page");
    return JSON.parse(json).props;
}

/** Props from the HTML of an HTTP response (the same `data-page` attribute, still HTML-escaped). */
export async function responseProps(response) {
    const html = await response.text();
    const escaped = html.match(/data-page="([^"]*)"/)?.[1];
    if (!escaped) {
        throw new Error(`No Inertia page in response from ${response.url()}`);
    }

    const json = escaped
        .replaceAll("&quot;", '"')
        .replaceAll("&#039;", "'")
        .replaceAll("&lt;", "<")
        .replaceAll("&gt;", ">")
        .replaceAll("&amp;", "&");

    return JSON.parse(json).props;
}

/** Inertia component name of the current full page load, e.g. "Dashboard/Index". */
export async function pageComponent(page) {
    const json = await page.locator("#app").getAttribute("data-page");
    return JSON.parse(json).component;
}

/**
 * POST JSON from inside the browser so the session cookie and CSRF token
 * are sent exactly like the app's own requests.
 */
export async function postJson(page, url, body) {
    return page.evaluate(
        async ({ url, body }) => {
            const xsrf = document.cookie
                .split("; ")
                .find((c) => c.startsWith("XSRF-TOKEN="))
                ?.split("=")[1];

            const res = await fetch(url, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-XSRF-TOKEN": decodeURIComponent(xsrf ?? ""),
                    // Marks the suite's own request so an expected 403 isn't reported as unusual.
                    "X-E2E-Probe": "1",
                },
                body: JSON.stringify(body),
            });

            return { status: res.status, body: await res.json().catch(() => null) };
        },
        { url, body },
    );
}

/** Signs in through the real login form. */
export async function login(page, { email, password }) {
    await page.goto("/login");
    await page.getByLabel("Email Address").fill(email);
    await page.getByLabel("Password", { exact: true }).fill(password);
    await page.getByRole("button", { name: "Sign In" }).click();
    // Login is slow on a local server when several workers sign in at once.
    await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 });
}
