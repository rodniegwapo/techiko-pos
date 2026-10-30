// Voiceover: each scene's narration read by Groq's Orpheus voice, one WAV per scene.
// Orpheus takes about 200 characters a request, so a scene is read sentence by sentence and the
// pieces joined with ffmpeg. Writes audio/scene-N.wav and audio/scenes.json (start and length).
//   node scripts/tts.mjs [--voice hannah] [--only scene-3]
import { mkdirSync, writeFileSync, rmSync, existsSync } from "node:fs";
import { join } from "node:path";
import { AUDIO_DIR, groqKey, scenes, run, duration } from "./groq.mjs";

const arg = (name, fallback) => {
    const i = process.argv.indexOf(`--${name}`);
    return i > -1 ? process.argv[i + 1] : fallback;
};
const VOICE = arg("voice", "hannah");
const ONLY = arg("only", null);
const MODEL = "canopylabs/orpheus-v1-english";
const MAX = 200;
const GAP = 0.25; // silence between sentences
const SCENE_PAD = 0.6; // silence after each scene before the next one starts

/** Sentences, and a sentence too long for one request split at its commas. */
function pieces(text) {
    const out = [];
    for (const sentence of text.match(/[^.!?]+[.!?]+/g) ?? [text]) {
        let part = "";
        for (const bit of sentence.trim().split(/(?<=,)\s+/)) {
            if (part && (part + " " + bit).length > MAX) {
                out.push(part);
                part = bit;
            } else {
                part = part ? `${part} ${bit}` : bit;
            }
        }
        if (part) out.push(part);
    }
    return out;
}

async function speak(text, file, key) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        const res = await fetch("https://api.groq.com/openai/v1/audio/speech", {
            method: "POST",
            headers: { Authorization: `Bearer ${key}`, "Content-Type": "application/json" },
            body: JSON.stringify({ model: MODEL, voice: VOICE, input: text, response_format: "wav" }),
        });
        if (res.ok) return writeFileSync(file, Buffer.from(await res.arrayBuffer()));
        const body = await res.text();
        if (res.status !== 429 || attempt === 3) throw new Error(`Groq TTS ${res.status}: ${body}`);
        await new Promise((r) => setTimeout(r, 5000 * attempt));
    }
}

const key = groqKey();
mkdirSync(AUDIO_DIR, { recursive: true });
const silence = join(AUDIO_DIR, "gap.wav");
run("ffmpeg", ["-y", "-v", "error", "-f", "lavfi", "-i", `anullsrc=r=24000:cl=mono`, "-t", String(GAP), silence]);

for (const scene of scenes()) {
    if (ONLY && scene.id !== ONLY) continue;
    const dir = join(AUDIO_DIR, scene.id);
    rmSync(dir, { recursive: true, force: true });
    mkdirSync(dir, { recursive: true });

    const parts = pieces(scene.say);
    const files = [];
    for (const [i, text] of parts.entries()) {
        const file = join(dir, `${String(i).padStart(2, "0")}.wav`);
        await speak(text, file, key);
        files.push(file, silence);
        process.stdout.write(`${scene.id} ${i + 1}/${parts.length}\r`);
    }

    // Same rate and channels for every piece, then one file with a pause at the end.
    const inputs = files.flatMap((f) => ["-i", f]);
    const filter = files.map((_, i) => `[${i}:a]aresample=24000,aformat=channel_layouts=mono[a${i}]`).join(";")
        + ";" + files.map((_, i) => `[a${i}]`).join("") + `concat=n=${files.length}:v=0:a=1,apad=pad_dur=${SCENE_PAD}[out]`;
    run("ffmpeg", ["-y", "-v", "error", ...inputs, "-filter_complex", filter, "-map", "[out]", join(AUDIO_DIR, `${scene.id}.wav`)]);
    console.log(`${scene.id}: ${parts.length} pieces, ${duration(join(AUDIO_DIR, `${scene.id}.wav`)).toFixed(2)}s`);
}

// Where each scene starts on the timeline: index.html and captions.mjs read this.
let start = 0;
const timeline = scenes()
    .filter((s) => existsSync(join(AUDIO_DIR, `${s.id}.wav`)))
    .map((s) => {
        const length = Number(duration(join(AUDIO_DIR, `${s.id}.wav`)).toFixed(3));
        const row = { id: s.id, start: Number(start.toFixed(3)), duration: length };
        start += length;
        return row;
    });
writeFileSync(join(AUDIO_DIR, "scenes.json"), JSON.stringify({ voice: VOICE, total: Number(start.toFixed(3)), scenes: timeline }, null, 2));

// The whole narration in one file, for captions and the final mix.
const list = timeline.map((s) => `file '${join(AUDIO_DIR, `${s.id}.wav`).replace(/\\/g, "/")}'`).join("\n");
writeFileSync(join(AUDIO_DIR, "list.txt"), list);
run("ffmpeg", ["-y", "-v", "error", "-f", "concat", "-safe", "0", "-i", join(AUDIO_DIR, "list.txt"), "-c", "copy", join(AUDIO_DIR, "narration.wav")]);
console.log(`narration.wav: ${start.toFixed(2)}s`);
