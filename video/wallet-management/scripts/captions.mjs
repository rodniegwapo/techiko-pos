// Captions and word timings. Groq Whisper transcribes audio/narration.wav with word timings; words
// are grouped into short lines (one sentence at most, about 6 words) for the caption layer and
// captions.srt. words.json keeps every word's time: the scenes time their reveals to it.
//   node scripts/captions.mjs            Groq Whisper (whisper-large-v3-turbo)
//   node scripts/captions.mjs --draft    no Groq: word times estimated from tts.mjs's sentence timings
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { ROOT, AUDIO_DIR, groqKey } from "./groq.mjs";

const MAX_WORDS = 6;
const MAX_GAP = 0.45; // a pause this long starts a new line
const DRAFT = process.argv.includes("--draft");

async function whisperWords() {
    const form = new FormData();
    form.append("file", new Blob([readFileSync(join(AUDIO_DIR, "narration.wav"))], { type: "audio/wav" }), "narration.wav");
    form.append("model", "whisper-large-v3-turbo");
    form.append("language", "en");
    form.append("response_format", "verbose_json");
    // The script's own words, so Whisper spells the app's labels the same way.
    form.append("prompt", "Cash and wallet. Today's drawer. Where money is. Cash in and out. Shifts and counts. Cash on hand. GCash. Owner took cash. Owner used it.");
    form.append("timestamp_granularities[]", "word");
    form.append("timestamp_granularities[]", "segment");

    const res = await fetch("https://api.groq.com/openai/v1/audio/transcriptions", {
        method: "POST",
        headers: { Authorization: `Bearer ${groqKey()}` },
        body: form,
    });
    if (!res.ok) throw new Error(`Groq Whisper ${res.status}: ${await res.text()}`);
    const { words = [] } = await res.json();
    if (!words.length) throw new Error("Whisper returned no word timings.");
    return words.map((w) => ({ text: w.word.trim(), start: w.start, end: w.end }));
}

/** Each sentence's time from tts.mjs, shared across its words by their length. */
function draftWords() {
    const { scenes } = JSON.parse(readFileSync(join(AUDIO_DIR, "scenes.json"), "utf8"));
    const words = [];
    for (const scene of scenes) {
        for (const piece of JSON.parse(readFileSync(join(AUDIO_DIR, scene.id, "pieces.json"), "utf8"))) {
            const bits = piece.text.split(/\s+/).filter(Boolean);
            const weight = bits.map((b) => b.length + 2);
            const total = weight.reduce((a, b) => a + b, 0);
            let at = scene.start + piece.start;
            const span = piece.end - piece.start;
            bits.forEach((text, i) => {
                const length = (span * weight[i]) / total;
                words.push({ text, start: at, end: at + length * 0.92 });
                at += length;
            });
        }
    }
    return words;
}

const words = (DRAFT ? draftWords() : await whisperWords()).map((w, i) => ({
    id: `w${i}`,
    text: w.text,
    start: Number(w.start.toFixed(3)),
    end: Number(w.end.toFixed(3)),
}));
writeFileSync(join(ROOT, "words.json"), JSON.stringify(words));

// A line ends at a sentence end, a pause, or MAX_WORDS.
const lines = [];
let line = null;
for (const w of words) {
    const prev = line?.words.at(-1);
    const breakHere = !line || line.words.length >= MAX_WORDS || w.start - line.end > MAX_GAP || /[.!?]$/.test(prev?.text ?? "");
    if (breakHere) {
        line = { start: w.start, end: w.end, words: [] };
        lines.push(line);
    }
    line.words.push(w);
    line.end = w.end;
}
const rows = lines.map((l) => ({ start: l.start, end: l.end, text: l.words.map((w) => w.text).join(" ") }));
writeFileSync(join(ROOT, "captions.json"), JSON.stringify(rows, null, 2));

const stamp = (s) => {
    const ms = Math.round(s * 1000);
    const p = (n, w = 2) => String(n).padStart(w, "0");
    return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`;
};
writeFileSync(join(ROOT, "captions.srt"), rows.map((r, i) => `${i + 1}\n${stamp(r.start)} --> ${stamp(r.end)}\n${r.text}\n`).join("\n"));
console.log(`${rows.length} caption lines, ${words.length} words${DRAFT ? " (draft timings)" : ""}`);
