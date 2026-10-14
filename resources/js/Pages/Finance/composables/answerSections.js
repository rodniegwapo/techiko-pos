/**
 * Splits a plain-text AI answer into its parts: paragraphs and "- " bullet lines, with
 * "What to watch:" / "Suggested next steps:" starting a new headed section.
 */
export function answerSections(text) {
    if (!text) return [];
    const out = [{ heading: null, paragraphs: [], bullets: [] }];
    for (const raw of text.split("\n")) {
        const line = raw.trim();
        if (!line) continue;
        if (/^(what to watch|suggested next steps)\s*:?$/i.test(line)) {
            out.push({ heading: line.replace(/:$/, ""), paragraphs: [], bullets: [] });
            continue;
        }
        const current = out[out.length - 1];
        if (/^[-•*]\s+/.test(line)) current.bullets.push(line.replace(/^[-•*]\s+/, ""));
        else current.paragraphs.push(line);
    }
    return out.filter((s) => s.paragraphs.length || s.bullets.length);
}
