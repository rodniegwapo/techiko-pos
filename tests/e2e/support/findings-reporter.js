import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, relative } from "node:path";

/**
 * Writes a Markdown report meant for a person to read after a run: what failed and why,
 * and anything unusual the browser saw even in passing tests (console errors, JS
 * exceptions, 4xx/5xx, failed or slow requests, and `observe()` notes from specs).
 */

const SECTIONS = [
    ["js-exception", "JavaScript exceptions", "The page threw an error that wasn't caught; something on screen may be broken."],
    ["server-error", "Server errors (5xx)", "Laravel crashed handling these requests. Check storage/logs/laravel.log."],
    ["client-error", "Unexpected 4xx responses", "The app itself requested these and was refused or they don't exist (404, 403, 419 CSRF, 422)."],
    ["request-failed", "Failed requests", "The request never completed (network error, blocked, connection refused)."],
    ["console-error", "Browser console errors", "Logged as errors by the app or a library."],
    ["slow-request", "Slow requests", "Page loads or API calls that took longer than the threshold (E2E_SLOW_MS, default 3000ms)."],
    ["console-warning", "Browser console warnings", "Usually lower priority (e.g. Vue warnings), but can explain odd behavior."],
];

const toRepoPath = (path) => relative(process.cwd(), path).replaceAll("\\", "/");
const stripAnsi = (text = "") => text.replace(/\u001b\[[0-9;]*m/g, "");
const escapeCell = (text) => String(text).replaceAll("|", "\\|").replaceAll("\n", " ");

export default class FindingsReporter {
    constructor({ outputFile = "tests/e2e/findings-report.md" } = {}) {
        this.outputFile = outputFile;
        this.tests = new Map();
    }

    printsToStdio() {
        return false;
    }

    onBegin(config) {
        this.config = config;
        this.startedAt = new Date();
    }

    onTestEnd(test, result) {
        // Keep the last attempt; retries overwrite earlier ones.
        this.tests.set(test.id, { test, result });
    }

    onEnd(fullResult) {
        const entries = [...this.tests.values()].map(({ test, result }) => ({
            test,
            result,
            outcome: test.outcome(),
            title: test.titlePath().slice(3).join(" > "),
            where: `${toRepoPath(test.location.file)}:${test.location.line}`,
        }));

        const md = [
            this.header(entries, fullResult),
            this.failures(entries.filter((e) => e.outcome === "unexpected")),
            this.flaky(entries.filter((e) => e.outcome === "flaky")),
            this.observations(entries),
            ...SECTIONS.map((section) => this.findingsSection(entries, ...section)),
            this.footer(),
        ]
            .filter(Boolean)
            .join("\n\n");

        mkdirSync(dirname(this.outputFile), { recursive: true });
        writeFileSync(this.outputFile, md + "\n");
        console.log(`\nFindings report: ${this.outputFile}`);
    }

    header(entries, fullResult) {
        const count = (outcome) => entries.filter((e) => e.outcome === outcome).length;
        const baseURL = this.config.projects[0]?.use?.baseURL ?? "";
        const seconds = Math.round(fullResult.duration / 1000);

        return [
            "# E2E findings report",
            "",
            `- **Run:** ${this.startedAt.toLocaleString()} (${seconds}s) against ${baseURL}`,
            `- **Result:** ${fullResult.status.toUpperCase()}`,
            `- **Tests:** ${count("expected")} passed, ${count("unexpected")} failed, ${count("flaky")} flaky, ${count("skipped")} skipped`,
        ].join("\n");
    }

    failures(failed) {
        if (!failed.length) return "## Failed tests\n\nNone.";

        const items = failed.map(({ title, where, result }) => {
            const message = stripAnsi(result.errors[0]?.message ?? "No error message")
                .split("\n")
                .filter((line) => line.trim())
                .slice(0, 6)
                .join("\n");
            const screenshots = result.attachments
                .filter((a) => a.name === "screenshot" && a.path)
                .map((a) => `  - Screenshot: \`${toRepoPath(a.path)}\``);

            return [`### ${title}`, `\`${where}\``, "", "```", message, "```", ...screenshots].join("\n");
        });

        return ["## Failed tests", "", "Each block is the expectation that didn't hold.", "", items.join("\n\n")].join("\n");
    }

    flaky(flaky) {
        if (!flaky.length) return null;
        const rows = flaky.map(({ title, where }) => `- ${title} (\`${where}\`)`);
        return ["## Flaky tests", "", "Failed at first, then passed on retry. Often timing or shared state.", "", ...rows].join("\n");
    }

    observations(entries) {
        const notes = new Map();
        for (const { test, result, title } of entries) {
            const annotations = [...(result.annotations ?? []), ...test.annotations];
            for (const { type, description } of annotations) {
                if (type !== "observation" || !description) continue;
                if (!notes.has(description)) notes.set(description, new Set());
                notes.get(description).add(title);
            }
        }

        if (!notes.size) return null;

        const rows = [...notes].map(([description, titles]) => `| ${escapeCell(description)} | ${seenIn(titles)} |`);
        return [
            "## Observations",
            "",
            "Tests passed, but noticed behavior that looks wrong or inconsistent.",
            "",
            "| What was seen | Seen in |",
            "| --- | --- |",
            ...rows,
        ].join("\n");
    }

    findingsSection(entries, kind, heading, explanation) {
        const grouped = new Map();

        for (const { result, title } of entries) {
            const attachment = result.attachments.find((a) => a.name === "findings" && a.body);
            if (!attachment) continue;

            for (const finding of JSON.parse(attachment.body.toString())) {
                if (finding.kind !== kind) continue;
                const group = grouped.get(finding.detail) ?? { count: 0, maxMs: 0, titles: new Set() };
                group.count++;
                group.maxMs = Math.max(group.maxMs, finding.ms ?? 0);
                group.titles.add(title);
                grouped.set(finding.detail, group);
            }
        }

        if (!grouped.size) return null;

        const slow = kind === "slow-request";
        const rows = [...grouped]
            .sort((a, b) => (slow ? b[1].maxMs - a[1].maxMs : b[1].count - a[1].count))
            .map(([detail, g]) =>
                slow
                    ? `| ${g.count} | ${g.maxMs}ms | ${escapeCell(detail)} | ${seenIn(g.titles)} |`
                    : `| ${g.count} | ${escapeCell(detail)} | ${seenIn(g.titles)} |`,
            );

        return [
            `## ${heading}`,
            "",
            explanation,
            "",
            slow ? "| Times | Slowest | Request | Seen in |" : "| Times | Detail | Seen in |",
            slow ? "| --- | --- | --- | --- |" : "| --- | --- | --- |",
            ...rows,
        ].join("\n");
    }

    footer() {
        return [
            "---",
            "",
            "Full details (steps, traces, videos of failures): `npm run test:e2e:report`.",
        ].join("\n");
    }
}

function seenIn(titles) {
    const list = [...titles];
    const shown = list.slice(0, 3).map(escapeCell).join("<br>");
    return list.length > 3 ? `${shown}<br>...and ${list.length - 3} more` : shown;
}
