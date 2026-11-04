// Final pass with ffmpeg on the HyperFrames render: loudness to -16 LUFS (two-pass loudnorm),
// fast start for streaming, and captions.srt as a subtitle track viewers can turn on.
//   node scripts/finish.mjs [render.mp4]   →  out/cash-and-wallet-explainer.mp4
import { spawnSync } from "node:child_process";
import { existsSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { ROOT, run, duration } from "./groq.mjs";

const OUT_DIR = join(ROOT, "out");
const input = process.argv[2]
    ?? readdirSync(OUT_DIR)
        .filter((f) => f.endsWith(".mp4") && !f.startsWith("cash-and-wallet-explainer"))
        .map((f) => join(OUT_DIR, f))
        .sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];
if (!input || !existsSync(input)) throw new Error("No render in out/: run `npm run render` first.");

// Pass 1: measure. ffmpeg prints loudnorm's JSON on stderr.
const TARGET = "I=-16:TP=-1.5:LRA=11";
const log = spawnSync("ffmpeg", ["-hide_banner", "-i", input, "-af", `loudnorm=${TARGET}:print_format=json`, "-f", "null", "-"], { encoding: "utf8" }).stderr;
const measured = JSON.parse(log.slice(log.lastIndexOf("{"), log.lastIndexOf("}") + 1));

// Pass 2: apply, copy the picture, add the subtitles.
const out = join(OUT_DIR, "cash-and-wallet-explainer.mp4");
const srt = join(ROOT, "captions.srt");
const af = `loudnorm=${TARGET}:measured_I=${measured.input_i}:measured_TP=${measured.input_tp}:measured_LRA=${measured.input_lra}:measured_thresh=${measured.input_thresh}:offset=${measured.target_offset}:linear=true`;
run("ffmpeg", [
    "-y", "-v", "error", "-i", input, ...(existsSync(srt) ? ["-i", srt] : []),
    "-map", "0:v", "-map", "0:a", ...(existsSync(srt) ? ["-map", "1:s", "-c:s", "mov_text", "-metadata:s:s:0", "language=eng"] : []),
    "-c:v", "copy", "-af", af, "-ar", "48000", "-c:a", "aac", "-b:a", "192k", "-movflags", "+faststart", out,
]);
console.log(`${out}: ${duration(out).toFixed(1)}s (was ${measured.input_i} LUFS, now -16)`);
