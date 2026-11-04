// Voiceover: each scene's narration read by Groq's Orpheus voice, one WAV per scene.
// Orpheus takes about 200 characters a request, so a scene is read sentence by sentence and the
// pieces joined with ffmpeg. Writes audio/scene-N.wav, audio/scenes.json (start and length)
// and audio/scene-N/pieces.json (each sentence's time in the scene).
//   node scripts/tts.mjs [--voice hannah] [--only scene-3]
//   node scripts/tts.mjs --resume  only the scenes not voiced yet (after a rate limit)
//   node scripts/tts.mjs --draft   Windows' own voice, no Groq: a stand-in to lay out the video before the real voice.
import { mkdirSync, writeFileSync, rmSync, existsSync } from "node:fs";
import { join } from "node:path";
import { execFileSync } from "node:child_process";
import { AUDIO_DIR, groqKey, scenes, run, duration } from "./groq.mjs";

const arg = (name, fallback) => {
    const i = process.argv.indexOf(`--${name}`);
    return i > -1 ? process.argv[i + 1] : fallback;
};
const VOICE = arg("voice", "hannah");
const ONLY = arg("only", null);
const DRAFT = process.argv.includes("--draft");
const RESUME = process.argv.includes("--resume");
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

/** Draft voice: Windows' System.Speech, text passed in the environment so no quoting can break it. */
function speakDraft(text, file) {
    const script = "Add-Type -AssemblyName System.Speech; $s = New-Object System.Speech.Synthesis.SpeechSynthesizer; $s.Rate = 1; $s.SetOutputToWaveFile($env:TTS_FILE); $s.Speak($env:TTS_TEXT); $s.Dispose()";
    execFileSync("powershell.exe", ["-NoProfile", "-NonInteractive", "-Command", script], { env: { ...process.env, TTS_FILE: file, TTS_TEXT: text }, stdio: "pipe" });
}

async function speak(text, file, key) {
    if (DRAFT) return speakDraft(text, file);
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

const key = DRAFT ? null : groqKey();
mkdirSync(AUDIO_DIR, { recursive: true });
const silence = join(AUDIO_DIR, "gap.wav");
run("ffmpeg", ["-y", "-v", "error", "-f", "lavfi", "-i", `anullsrc=r=24000:cl=mono`, "-t", String(GAP), silence]);

for (const scene of scenes()) {
    if (ONLY && scene.id !== ONLY) continue;
    if (RESUME && existsSync(join(AUDIO_DIR, scene.id, "pieces.json"))) continue;
    const dir = join(AUDIO_DIR, scene.id);
    rmSync(dir, { recursive: true, force: true });
    mkdirSync(dir, { recursive: true });

    const parts = pieces(scene.say);
    const files = [];
    const spoken = [];
    let at = 0;
    for (const [i, text] of parts.entries()) {
        const file = join(dir, `${String(i).padStart(2, "0")}.wav`);
        await speak(text, file, key);
        files.push(file, silence);
        const length = duration(file);
        spoken.push({ text, start: Number(at.toFixed(3)), end: Number((at + length).toFixed(3)) });
        at += length + GAP;
        process.stdout.write(`${scene.id} ${i + 1}/${parts.length}\r`);
    }

    // Same rate and channels for every piece, then one file with a pause at the end.
    const inputs = files.flatMap((f) => ["-i", f]);
    const filter = files.map((_, i) => `[${i}:a]aresample=24000,aformat=channel_layouts=mono[a${i}]`).join(";")
        + ";" + files.map((_, i) => `[a${i}]`).join("") + `concat=n=${files.length}:v=0:a=1,apad=pad_dur=${SCENE_PAD}[out]`;
    run("ffmpeg", ["-y", "-v", "error", ...inputs, "-filter_complex", filter, "-map", "[out]", join(AUDIO_DIR, `${scene.id}.wav`)]);
    // Where each sentence sits inside the scene: captions.mjs --draft estimates word times from it.
    writeFileSync(join(dir, "pieces.json"), JSON.stringify(spoken, null, 2));
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
writeFileSync(join(AUDIO_DIR, "scenes.json"), JSON.stringify({ voice: DRAFT ? "draft" : VOICE, total: Number(start.toFixed(3)), scenes: timeline }, null, 2));

// The whole narration in one file, for captions and the final mix.
const list = timeline.map((s) => `file '${join(AUDIO_DIR, `${s.id}.wav`).replace(/\\/g, "/")}'`).join("\n");
writeFileSync(join(AUDIO_DIR, "list.txt"), list);
run("ffmpeg", ["-y", "-v", "error", "-f", "concat", "-safe", "0", "-i", join(AUDIO_DIR, "list.txt"), "-c", "copy", join(AUDIO_DIR, "narration.wav")]);
console.log(`narration.wav: ${start.toFixed(2)}s`);
