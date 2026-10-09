/**
 * A sale paid in parts (cash + GCash, card + credit…). The same rules the server checks:
 * 2 to 4 parts; cash and credit once each; card, e-wallet and bank name their channel;
 * only cash may come to more than what is left (the rest is change).
 */
export const SPLIT_METHODS = [
    { value: "cash", label: "Cash" },
    { value: "card", label: "Card" },
    { value: "e-wallet", label: "E-wallet" },
    { value: "bank", label: "Bank" },
    { value: "credit", label: "Credit" },
];

/** Which kind of channel each method is paid through. */
export const SPLIT_CHANNEL_KIND = { card: "card", "e-wallet": "ewallet", bank: "bank" };

let nextKey = 1;

export function newSplitRow(method = "cash", amount = null) {
    return {
        key: nextKey++,
        method,
        amount,
        payment_card_type_id: null,
        channel_name: null,
        payment_reference: "",
    };
}

const cents = (v) => Math.round(Number(v || 0) * 100);

/** Totals of the parts against what is owed, in pesos. */
export function splitTotals(rows, grandTotal) {
    const total = cents(grandTotal);
    const nonCash = rows
        .filter((r) => r.method !== "cash")
        .reduce((s, r) => s + cents(r.amount), 0);
    const cash = rows
        .filter((r) => r.method === "cash")
        .reduce((s, r) => s + cents(r.amount), 0);
    const paid = nonCash + cash;

    return {
        paid: paid / 100,
        remaining: Math.max(0, total - paid) / 100,
        change: nonCash <= total ? Math.max(0, paid - total) / 100 : 0,
        nonCashOver: nonCash > total,
    };
}

/** Why these parts can't be taken yet, or null when they can. */
export function splitProblem(rows, grandTotal, { allowCredit = true } = {}) {
    if (rows.length < 2) return "Add at least two payments.";
    if (rows.some((r) => !(Number(r.amount) > 0))) return "Enter an amount for each payment.";

    const uses = (m) => rows.filter((r) => r.method === m).length;
    if (uses("cash") > 1 || uses("credit") > 1) {
        return "Cash and credit can each be used once.";
    }
    if (!allowCredit && uses("credit") > 0) return "Credit can't be used here.";

    const missing = rows.find((r) => SPLIT_CHANNEL_KIND[r.method] && !r.payment_card_type_id);
    if (missing) {
        const word = { card: "card type", "e-wallet": "e-wallet", bank: "bank" }[missing.method];
        return `Choose the ${word} for the ${missing.method} payment.`;
    }

    const t = splitTotals(rows, grandTotal);
    if (t.nonCashOver) return "Only cash can come to more than the total.";
    if (t.remaining > 0) return `₱${t.remaining.toFixed(2)} still to pay.`;

    return null;
}

/** The parts as the payment endpoints take them. */
export function splitPayload(rows) {
    return rows.map((r) => {
        const part = { method: r.method, amount: Number(r.amount) };
        if (SPLIT_CHANNEL_KIND[r.method]) {
            part.payment_card_type_id = Number(r.payment_card_type_id);
            const ref = String(r.payment_reference || "").trim();
            if (r.method !== "card" && ref) part.payment_reference = ref;
        }
        return part;
    });
}
