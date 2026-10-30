// Captions: Groq Whisper transcribes audio/narration.wav with word timings. Words are grouped
// into short lines (one sentence at most, about 7 words) for the caption layer and captions.srt.
//   node scripts/captions.mjs
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { ROOT, AUDIO_DIR, groqKey } from "./groq.mjs";

const MAX_WORDS = 7;
const MAX_GAP = 0.6; // a pause this long starts a new line

const form = new FormData();
form.append("file", new Blob([readFileSync(join(AUDIO_DIR, "narration.wav"))], { type: "audio/wav" }), "narration.wav");
form.append("model", "whisper-large-v3-turbo");
form.append("language", "en");
form.append("response_format", "verbose_json");
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

// A line ends at a sentence end (when the word carries its punctuation), a pause, or MAX_WORDS.
const lines = [];
let line = null;
for (const w of words) {
    const text = w.word.trim();
    const breakHere = !line || line.words.length >= MAX_WORDS || w.start - line.end > MAX_GAP || /[.!?]$/.test(line.words.at(-1));
    if (breakHere) {
        line = { start: w.start, end: w.end, words: [] };
        lines.push(line);
    }
    line.words.push(text);
    line.end = w.end;
}
const rows = lines.map((l) => ({ start: Number(l.start.toFixed(2)), end: Number(l.end.toFixed(2)), text: l.words.join(" ") }));

writeFileSync(join(ROOT, "captions.json"), JSON.stringify(rows, null, 2));

const stamp = (s) => {
    const ms = Math.round(s * 1000);
    const p = (n, w = 2) => String(n).padStart(w, "0");
    return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`;
};
writeFileSync(join(ROOT, "captions.srt"), rows.map((r, i) => `${i + 1}\n${stamp(r.start)} --> ${stamp(r.end)}\n${r.text}\n`).join("\n"));
console.log(`${rows.length} caption lines, ${words.length} words`);
