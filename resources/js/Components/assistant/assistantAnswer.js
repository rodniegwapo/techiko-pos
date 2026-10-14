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
    if (name.startsWith("expenses") || name.startsWith("finance.payables") || name.startsWith("finance.other-income")) {
        return ["Record an expense for me", "How do I set up a monthly expense?", "How do I record a supplier bill?"];
    }
    if (name.startsWith("finance") || name.startsWith("profit-loss") || name.startsWith("vat-report")) {
        return ["What was our profit this month?", "Why is my cash lower than my profit?", "How do I record a loan?"];
    }
    if (name.startsWith("wallet") || name.startsWith("payment-card-types")) {
        return ["How do I end the shift?", "How much cash should be in the drawer?", "Add GCash as a payment option"];
    }
    if (name.startsWith("products.discounts") || name.startsWith("mandatory-discounts")) {
        return ["Create a 10% payday promo for me", "How do I set the senior discount rate?"];
    }
    if (name.startsWith("products.modifier")) {
        return ["How do I add sizes like small, medium, large?", "How do I add add-ons with extra price?"];
    }
    if (name.startsWith("products") || name.startsWith("categories")) {
        return ["Add a product for me", "Change a product's price", "How do I add a category?"];
    }
    if (name.startsWith("inventory.locations")) {
        return ["Add a new store for me", "Update my store's address", "How do I make a store the default?"];
    }
    if (name.startsWith("inventory.adjustments")) {
        return ["How do I correct stock for damaged items?", "How do I approve a stock adjustment?"];
    }
    if (name.startsWith("inventory")) {
        return ["How do I receive stock?", "How do I transfer stock to another store?", "Where do I see low-stock items?"];
    }
    if (name.startsWith("settings")) {
        return ["Turn on VAT for me", "How do I hide out-of-stock products?"];
    }
    if (name.startsWith("sales-history") || name.startsWith("voids")) {
        return ["How do I void a sale?", "How do I reprint a receipt?", "How do I export sales?"];
    }
    if (name.startsWith("sales")) {
        return ["How do I make a sale?", "How do I give a senior discount?", "How do I split a payment?"];
    }
    if (name.startsWith("credits")) {
        return ["How do I record a credit payment?", "How do I set a credit limit?", "Who has overdue credit?"];
    }
    if (name.startsWith("customers") || name.startsWith("loyalty")) {
        return ["How do I add a customer?", "How do I add loyalty points?", "How do I set up loyalty tiers?"];
    }
    if (name.startsWith("users")) {
        return ["How do I add a cashier?", "How do I set a user's PIN?", "How do I assign a supervisor?"];
    }
    return ["Add a product for me", "How do I update the store?", "What can you help me with?"];
}
