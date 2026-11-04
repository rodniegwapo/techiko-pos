// Times inside a scene from what the voice says: `at("scene-4", "End my shift")` is when those words
// start, in seconds from the scene's start. Reads words.json (captions.mjs) and audio/scenes.json
// (tts.mjs). A phrase Whisper heard differently falls back to where it sits in the script's text.
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { ROOT, AUDIO_DIR, scenes as scriptScenes } from "./groq.mjs";

const norm = (s) => s.toLowerCase().replace(/[’']/g, "").replace(/[^a-z0-9]+/g, " ").trim();

export const timeline = JSON.parse(readFileSync(join(AUDIO_DIR, "scenes.json"), "utf8"));
const words = existsSync(join(ROOT, "words.json")) ? JSON.parse(readFileSync(join(ROOT, "words.json"), "utf8")) : [];
const says = Object.fromEntries(scriptScenes().map((s) => [s.id, s.say]));
const missed = [];

export function slot(id) {
    const s = timeline.scenes.find((x) => x.id === id);
    if (!s) throw new Error(`${id} is not in audio/scenes.json: run tts.mjs`);
    return s;
}

/**
 * When `phrase` starts in scene `id` (seconds from the scene's start). "phrase#2" is its second
 * time. The first three words must match in order; punctuation and case don't count.
 */
export function at(id, phrase) {
    const [text, nth = "1"] = phrase.split("#");
    const s = slot(id);
    const want = norm(text).split(" ").slice(0, 3);
    const mine = words.filter((w) => w.start >= s.start - 0.05 && w.start < s.start + s.duration);
    let seen = 0;
    for (let i = 0; i + want.length <= mine.length; i++) {
        if (want.every((t, k) => norm(mine[i + k].text) === t) && ++seen === Number(nth)) {
            return Math.max(0, Number((mine[i].start - s.start).toFixed(3)));
        }
    }
    // Fallback: the phrase's place in the script, as a share of the scene.
    const say = norm(says[id] ?? "");
    let pos = -1;
    for (let n = 0; n < Number(nth); n++) pos = say.indexOf(norm(text), pos + 1);
    missed.push(`${id}: "${phrase}"${pos < 0 ? " (not in the script either)" : ""}`);
    return pos < 0 ? 0 : Number(((pos / say.length) * s.duration).toFixed(3));
}

export function reportMissed() {
    if (missed.length) console.warn(`Timed from the script, not the voice:\n  ${[...new Set(missed)].join("\n  ")}`);
}
