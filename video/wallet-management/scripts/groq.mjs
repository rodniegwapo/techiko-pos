// Shared bits for the Groq scripts: the API key, the script's scenes, and ffmpeg/ffprobe.
import { readFileSync, existsSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
export const AUDIO_DIR = join(ROOT, "audio");

/** GROQ_API_KEY from the environment, else from this project's own .env (not the app's). */
export function groqKey() {
    if (process.env.GROQ_API_KEY) return process.env.GROQ_API_KEY;
    const file = join(ROOT, ".env");
    if (existsSync(file)) {
        const line = readFileSync(file, "utf8").split(/\r?\n/).find((l) => l.startsWith("GROQ_API_KEY="));
        const key = line?.slice("GROQ_API_KEY=".length).trim().replace(/^["']|["']$/g, "");
        if (key) return key;
    }
    console.error("Add GROQ_API_KEY=... to video/wallet-management/.env (get one at console.groq.com/keys).");
    process.exit(1);
}

/** Each "## scene-N" of script.md with its **Say:** paragraph. */
export function scenes() {
    const text = readFileSync(join(ROOT, "script.md"), "utf8");
    return text
        .split(/^## /m)
        .slice(1)
        .map((block) => {
            const id = block.match(/^(scene-\d+)/)?.[1];
            const say = block.match(/\*\*Say:\*\*\s*([\s\S]*?)(?=\n\s*\n|$)/)?.[1]?.replace(/\s+/g, " ").trim();
            return id && say ? { id, say } : null;
        })
        .filter(Boolean);
}

export const run = (cmd, args) => execFileSync(cmd, args, { stdio: ["ignore", "pipe", "pipe"] }).toString();

export const duration = (file) =>
    Number(run("ffprobe", ["-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", file]).trim());
