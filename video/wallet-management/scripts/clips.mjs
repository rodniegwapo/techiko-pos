// Footage: the Playwright clips (clips/<clip>[a-c].webm) cut, joined and fitted with ffmpeg to the
// window scenes.config.mjs gives them. Each clip's .json says where its scene starts (the login is
// cut); parts are joined a, b, c; the result is sped up or slowed (0.6× to maxRate, default 1.6×)
// to fit, then the last frame is held for whatever is left. Writes assets/footage/<clip>.mp4
// (H.264, 30 fps, 1600×900) and assets/footage/windows.json (where each one plays in its scene).
//   node scripts/clips.mjs [--only scene-4]
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { ROOT, run, duration } from "./groq.mjs";
import { at, slot, reportMissed } from "./anchors.mjs";
import { SCENES } from "./scenes.config.mjs";

const CLIPS = join(ROOT, "clips");
const OUT = join(ROOT, "assets", "footage");
const TMP = join(OUT, ".parts");
const FPS = 30;
const MIN_RATE = 0.6;
const ONLY = process.argv.includes("--only") ? process.argv[process.argv.indexOf("--only") + 1] : null;

mkdirSync(TMP, { recursive: true });
const files = readdirSync(CLIPS).filter((f) => f.endsWith(".webm"));
const windowsFile = join(OUT, "windows.json");
const windows = existsSync(windowsFile) ? JSON.parse(readFileSync(windowsFile, "utf8")) : {};

for (const scene of SCENES) {
    if (ONLY && scene.id !== ONLY) continue;
    for (const f of scene.footage ?? []) {
        const s = slot(scene.id);
        const from = f.from ? at(scene.id, f.from) : 0;
        const to = f.to ? at(scene.id, f.to) : s.duration;
        const want = Math.max(1, to - from);

        const parts = files.filter((name) => new RegExp(`^${f.clip}[a-z]?\\.webm$`).test(name)).sort();
        if (!parts.length) {
            console.warn(`${f.clip}: no footage in clips/, skipped`);
            continue;
        }

        // 1. Each part from its marked start, at a constant 30 fps.
        const cut = parts.map((name, i) => {
            const meta = join(CLIPS, name.replace(/\.webm$/, ".json"));
            const trim = existsSync(meta) ? JSON.parse(readFileSync(meta, "utf8")).trimStart ?? 0 : 0;
            const out = join(TMP, `${f.clip}-${i}.mp4`);
            run("ffmpeg", ["-y", "-v", "error", "-ss", String(trim), "-i", join(CLIPS, name),
                "-vf", `fps=${FPS},scale=1600:900:flags=lanczos,format=yuv420p`, "-an",
                "-c:v", "libx264", "-preset", "medium", "-crf", "16", out]);
            return out;
        });

        // 2. Parts joined.
        const joined = join(TMP, `${f.clip}-joined.mp4`);
        if (cut.length === 1) {
            run("ffmpeg", ["-y", "-v", "error", "-i", cut[0], "-c", "copy", joined]);
        } else {
            const list = join(TMP, `${f.clip}.txt`);
            writeFileSync(list, cut.map((p) => `file '${p.replace(/\\/g, "/")}'`).join("\n"));
            run("ffmpeg", ["-y", "-v", "error", "-f", "concat", "-safe", "0", "-i", list, "-c", "copy", joined]);
        }

        // 3. Fitted to its window: speed within limits, then the last frame held to the end.
        const have = duration(joined);
        const rate = Math.min(f.maxRate ?? 1.6, Math.max(MIN_RATE, have / want));
        const hold = Math.max(0, want - have / rate);
        const out = join(OUT, `${f.clip}.mp4`);
        const vf = `setpts=PTS/${rate.toFixed(4)},fps=${FPS},tpad=stop_mode=clone:stop_duration=${(hold + 0.5).toFixed(3)}`;
        run("ffmpeg", ["-y", "-v", "error", "-i", joined, "-vf", vf, "-t", want.toFixed(3),
            "-c:v", "libx264", "-preset", "medium", "-crf", "18", "-pix_fmt", "yuv420p", "-r", String(FPS), "-movflags", "+faststart", out]);
        windows[f.clip] = { scene: scene.id, start: Number(from.toFixed(3)), duration: Number(want.toFixed(3)) };
        const lost = Math.max(0, have / rate - want);
        console.log(`${f.clip}: ${have.toFixed(1)}s at ${rate.toFixed(2)}× in ${want.toFixed(1)}s` + (hold ? `, last ${hold.toFixed(1)}s held` : "") + (lost > 0.3 ? `, last ${lost.toFixed(1)}s CUT` : ""));
    }
}

writeFileSync(windowsFile, JSON.stringify(windows, null, 2));
rmSync(TMP, { recursive: true, force: true });
reportMissed();
