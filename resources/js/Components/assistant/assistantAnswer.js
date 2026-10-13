/**
 * Splits the assistant's plain-text answer into blocks: paragraphs, numbered steps ("1. …") and
 * bullets ("- …"). Each line is split into text parts, with **bold** parts marked, so the answer
 * renders without v-html.
 */
export function answerBlocks(text) {
    if (!text) return [];
    const blocks = [];
    const push = (type, line) => {
        const last = blocks[blocks.length - 1];
        if (type !== "p" && last?.type === type) {
            last.items.push(parts(line));
        } else {
            blocks.push({ type, items: [parts(line)] });
        }
    };
    for (const raw of text.split("\n")) {
        const line = raw.trim();
        if (!line) continue;
        if (/^\d+[.)]\s+/.test(line)) push("ol", line.replace(/^\d+[.)]\s+/, ""));
        else if (/^[-•*]\s+/.test(line)) push("ul", line.replace(/^[-•*]\s+/, ""));
        else push("p", line);
    }
    return blocks;
}

function parts(line) {
    return line
        .split(/(\*\*[^*]+\*\*)/g)
        .filter(Boolean)
        .map((part) =>
            part.startsWith("**") && part.endsWith("**")
                ? { text: part.slice(2, -2), bold: true }
                : { text: part, bold: false },
        );
}

/** Starter questions that fit the page the user is on. */
export function suggestionsFor(routeName) {
    const name = (routeName || "").replace(/^domains\./, "");
    if (name.startsWith("products") || name.startsWith("categories")) {
        return ["Add a product for me", "Change a product's price", "How do I add a category?"];
    }
    if (name.startsWith("inventory.locations")) {
        return ["Add a new store for me", "Update my store's address", "How do I make a store the default?"];
    }
    if (name.startsWith("settings")) {
        return ["Turn on VAT for me", "How do I hide out-of-stock products?"];
    }
    if (name.startsWith("sales")) {
        return ["How do I make a sale?", "How do I switch the store I work in?"];
    }
    return ["Add a product for me", "How do I update the store?", "What can you help me with?"];
}
