/**
 * Turns the recorded walkthrough chapters (videos/<name>.webm + videos/<name>.json) into narrated
 * .mp4 files: each spoken caption is mixed in when it appeared on screen. Also joins the chapters
 * into one cash-and-wallet.mp4.
 *
 *   node tests/e2e/walkthrough/make-mp4.mjs "C:\path\to\out"
 *
 * Needs ffmpeg: on PATH, or FFMPEG=C:\path\to\ffmpeg.exe.
 */
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, readdirSync, unlinkSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const VIDEO_DIR = join(dirname(fileURLToPath(import.meta.url)), "videos");
const out = resolve(process.argv[2] || join(VIDEO_DIR, "mp4"));
const ffmpeg = process.env.FFMPEG || "ffmpeg";

const run = (args) => execFileSync(ffmpeg, ["-hide_banner", "-loglevel", "error", "-y", ...args], { stdio: "inherit" });

mkdirSync(out, { recursive: true });
const chapters = readdirSync(VIDEO_DIR).filter((f) => /^\d\d-.+\.webm$/.test(f)).sort().map((f) => f.replace(/\.webm$/, ""));
if (!chapters.length) throw new Error(`No recorded chapters in ${VIDEO_DIR}; record them first.`);

const made = [];
for (const name of chapters) {
    const timeline = join(VIDEO_DIR, `${name}.json`);
    const lines = (existsSync(timeline) ? JSON.parse(readFileSync(timeline, "utf8")).lines : []).filter((l) => existsSync(l.file));
    const target = join(out, `${name}.mp4`);

    const inputs = ["-i", join(VIDEO_DIR, `${name}.webm`)];
    for (const line of lines) inputs.push("-i", line.file);

    // Every chapter gets an audio track (silence when it has no narration), so they can be joined.
    const audio = lines.length
        ? `${lines.map((l, i) => `[${i + 1}:a]aresample=48000,adelay=${Math.max(0, Math.round(l.at))}:all=1[a${i}]`).join(";")};` +
          `${lines.map((_, i) => `[a${i}]`).join("")}amix=inputs=${lines.length}:normalize=0:dropout_transition=0,apad[aout]`
        : "anullsrc=r=48000:cl=stereo[aout]";

    run([
        ...inputs,
        "-filter_complex", audio,
        "-map", "0:v", "-map", "[aout]", "-shortest",
        "-c:v", "libx264", "-preset", "medium", "-crf", "20", "-pix_fmt", "yuv420p", "-r", "30",
        "-c:a", "aac", "-b:a", "160k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart",
        target,
    ]);
    made.push(target);
    console.log(`${name}.mp4  (${lines.length} spoken lines)`);
}

// One video with every chapter, in order (same encoding, so no re-encode).
const list = join(out, "chapters.txt");
writeFileSync(list, made.map((f) => `file '${f.replaceAll("\\", "/").replaceAll("'", "'\\''")}'`).join("\n"));
run(["-f", "concat", "-safe", "0", "-i", list, "-c", "copy", "-movflags", "+faststart", join(out, "cash-and-wallet.mp4")]);
unlinkSync(list);
console.log(`cash-and-wallet.mp4  (all ${made.length} chapters)\nSaved in ${out}`);
