import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { test as base, expect } from "@playwright/test";
import { login } from "../support/inertia.js";
import { resolveAccount } from "../support/users.js";

const HERE = dirname(fileURLToPath(import.meta.url));

/** Where the finished chapter videos (and their narration timelines) are copied. */
export const VIDEO_DIR = join(HERE, "videos");
/** Spoken captions, one .wav per text, reused between runs. */
const VOICE_DIR = join(HERE, "results", "narration");

/** How long a caption stays before the next step (ms); E2E_TOUR_PACE=1.5 slows the whole video. */
const PACE = Number(process.env.E2E_TOUR_PACE) || 1;
/** Windows voice reading the captions (E2E_TOUR_VOICE="Microsoft David Desktop"); E2E_TOUR_VOICE=off for none. */
const VOICE = process.env.E2E_TOUR_VOICE || "Microsoft Zira Desktop";
const ROLE_COLORS = { Cashier: "#0d9488", Supervisor: "#7c3aed", Manager: "#2563eb", Admin: "#b45309" };

/**
 * The walkthrough's own page: signed in as `account`, with the moment its video started, so the
 * narration lines up with the picture (the video starts when the page opens).
 */
export const test = base.extend({
    account: [null, { option: true }],
    page: async ({ context, account }, use, testInfo) => {
        const startedAt = Date.now();
        const page = await context.newPage();
        page.videoStartedAt = startedAt;
        if (account) await login(page, resolveAccount(account, testInfo.parallelIndex));
        await use(page);
    },
});
export { expect };

/** Speaks `text` into a .wav (Windows' own voice); returns the file and its length in ms, or null. */
function speak(text) {
    if (VOICE === "off" || process.platform !== "win32") return null;
    mkdirSync(VOICE_DIR, { recursive: true });
    const file = join(VOICE_DIR, `${createHash("sha1").update(`${VOICE}|${text}`).digest("hex").slice(0, 16)}.wav`);
    if (!existsSync(file)) {
        // The text goes through an environment variable, so no quoting can break the script.
        const script = [
            "Add-Type -AssemblyName System.Speech",
            "$s = New-Object System.Speech.Synthesis.SpeechSynthesizer",
            "$s.SelectVoice($env:TOUR_VOICE)",
            "$s.Rate = 0",
            "$s.SetOutputToWaveFile($env:TOUR_FILE)",
            "$s.Speak($env:TOUR_TEXT)",
            "$s.Dispose()",
        ].join("; ");
        try {
            execFileSync("powershell.exe", ["-NoProfile", "-NonInteractive", "-Command", script], {
                env: { ...process.env, TOUR_VOICE: VOICE, TOUR_FILE: file, TOUR_TEXT: text },
                stdio: "pipe",
            });
        } catch {
            return null;
        }
    }
    return { file, ms: wavMs(file) };
}

/** Length of a PCM .wav in ms, from its header. */
function wavMs(file) {
    const buf = readFileSync(file);
    const byteRate = buf.readUInt32LE(28);
    let offset = 12;
    while (offset + 8 <= buf.length) {
        const id = buf.toString("ascii", offset, offset + 4);
        const size = buf.readUInt32LE(offset + 4);
        if (id === "data") return Math.round((size / byteRate) * 1000);
        offset += 8 + size;
    }
    return 0;
}

/**
 * Captions, a narrating voice and highlights drawn over the app for walkthrough videos. Captions
 * live on <body>, outside Inertia's #app, so page changes inside the app keep them; after a full
 * page load call `say` again. Each spoken line is logged with when it started, for the mp4 step.
 */
export class Tour {
    constructor(page, role) {
        this.page = page;
        this.role = role;
        this.lines = [];
    }

    pause(ms) {
        return this.page.waitForTimeout(ms * PACE);
    }

    /** Starts a spoken line now; returns how long to hold so it isn't cut off. */
    voice(text) {
        const clip = speak(text);
        if (!clip) return 0;
        this.lines.push({ at: Date.now() - this.page.videoStartedAt, file: clip.file, ms: clip.ms, text });
        return clip.ms + 450;
    }

    /** Shows a caption at the bottom of the screen, reads it out and holds it long enough to follow. */
    async say(text, hold = 2600) {
        const clip = speak(text); // made before the caption shows, so the voice starts with it
        await this.page.evaluate(
            ({ text, role, color }) => {
                let bar = document.getElementById("tour-caption");
                if (!bar) {
                    bar = document.createElement("div");
                    bar.id = "tour-caption";
                    bar.style.cssText = "position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:2147483647;max-width:900px;display:flex;align-items:center;gap:12px;padding:14px 20px;border-radius:12px;background:rgba(17,24,39,.92);color:#fff;font:500 19px/1.4 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35);pointer-events:none;transition:opacity .25s";
                    document.body.appendChild(bar);
                }
                bar.innerHTML = "";
                const chip = document.createElement("span");
                chip.textContent = role;
                chip.style.cssText = `flex:none;padding:3px 10px;border-radius:999px;background:${color};font-size:14px;font-weight:700;letter-spacing:.02em`;
                const body = document.createElement("span");
                body.textContent = text;
                bar.append(chip, body);
                bar.style.opacity = "1";
            },
            { text, role: this.role, color: ROLE_COLORS[this.role] ?? "#374151" },
        );
        const spoken = clip ? this.voice(text) : 0;
        await this.pause(Math.max(hold, spoken / PACE));
    }

    /** A full-screen title card that opens a chapter, read out. */
    async title(heading, lines = [], hold = 3500) {
        const text = [heading, ...lines].join(" ");
        speak(text);
        await this.page.evaluate(
            ({ heading, lines, color }) => {
                const card = document.createElement("div");
                card.id = "tour-title";
                card.style.cssText = `position:fixed;inset:0;z-index:2147483647;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:linear-gradient(135deg,${color},#111827);color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px`;
                const h = document.createElement("div");
                h.textContent = heading;
                h.style.cssText = "font-size:44px;font-weight:800";
                card.appendChild(h);
                for (const line of lines) {
                    const p = document.createElement("div");
                    p.textContent = line;
                    p.style.cssText = "font-size:22px;opacity:.9;max-width:860px";
                    card.appendChild(p);
                }
                document.body.appendChild(card);
            },
            { heading, lines, color: ROLE_COLORS[this.role] ?? "#374151" },
        );
        await this.pause(Math.max(hold, this.voice(text) / PACE));
        await this.page.evaluate(() => document.getElementById("tour-title")?.remove());
    }

    /** Draws a box around an element for a moment, so viewers see where to look or click. */
    async point(locator, hold = 900) {
        await locator.scrollIntoViewIfNeeded();
        const box = await locator.boundingBox();
        if (!box) return;
        await this.page.evaluate((b) => {
            const ring = document.createElement("div");
            ring.className = "tour-ring";
            ring.style.cssText = `position:fixed;left:${b.x - 6}px;top:${b.y - 6}px;width:${b.width + 12}px;height:${b.height + 12}px;border:3px solid #f59e0b;border-radius:10px;box-shadow:0 0 0 4px rgba(245,158,11,.25);z-index:2147483646;pointer-events:none`;
            document.body.appendChild(ring);
        }, box);
        await this.pause(hold);
        await this.page.evaluate(() => document.querySelectorAll(".tour-ring").forEach((r) => r.remove()));
    }

    /** Points at an element, then clicks it. */
    async click(locator, hold = 700) {
        await this.point(locator, hold);
        await locator.click();
    }

    /** Types slowly enough to follow. */
    async type(locator, text) {
        await this.point(locator, 500);
        await locator.click();
        await locator.fill("");
        await locator.pressSequentially(String(text), { delay: 90 });
    }
}

/** Closes the page and saves its video as videos/<name>.webm, with videos/<name>.json: when each line is spoken. */
export async function saveVideo(page, name, tour) {
    mkdirSync(VIDEO_DIR, { recursive: true });
    const video = page.video();
    await page.close();
    await video?.saveAs(join(VIDEO_DIR, `${name}.webm`));
    writeFileSync(join(VIDEO_DIR, `${name}.json`), JSON.stringify({ lines: tour?.lines ?? [] }, null, 2));
}
