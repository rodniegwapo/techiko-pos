/**
 * The one place for the plain words the wallet pages use. A "payment channel" is a store's card
 * terminal, e-wallet or bank; the "cash drawer" is the store's physical cash.
 */

export const CHANNEL_KIND_LABELS = { card: "Card terminal", ewallet: "E-wallet", bank: "Bank" };

/** Lines staff add by hand on Cash in & out. */
export const MANUAL_KIND_LABELS = {
    cash_sale_topup: "Added float",
    owner_draw: "Owner took cash",
    ewallet_transfer_in: "Moved into channel",
    ewallet_transfer_out: "Moved out of channel",
    adjustment: "Correction",
};

/** Lines other pages post: read-only here, they change the drawer too. */
export const SYSTEM_KIND_LABELS = {
    expense: "Expense paid",
    supplier_payment: "Paid supplier",
    other_income: "Other income",
    loan_received: "Loan received",
    loan_payment: "Loan repayment",
    asset_purchase: "Equipment bought",
    owner_investment: "Owner put in money",
    sale_payment: "Sale",
    sale_void: "Sale voided",
    credit_payment: "Credit payment",
};

/** Lines Today's drawer writes itself; the server marks them in `notes`. */
export const AUTO_NOTE_LABELS = {
    AUTO_CC_OPENING: "Opening cash",
    AUTO_CC_COUNTED_VARIANCE: "Count difference",
    AUTO_CC_ENDSHIFT_CASHOUT: "Cash taken out at end of shift",
};

export function autoNoteLabel(notes) {
    const token = typeof notes === "string" ? notes.trim() : null;
    return token && AUTO_NOTE_LABELS[token] ? AUTO_NOTE_LABELS[token] : null;
}

/** What a ledger line is, in plain words. */
export function movementLabel(row) {
    const auto = autoNoteLabel(row.notes);
    if (auto) return auto;
    if (row.kind === "ewallet_transfer_in") return `Moved into ${row.payment_card_type?.name ?? "channel"}`;
    if (row.kind === "ewallet_transfer_out") return `Moved out of ${row.payment_card_type?.name ?? "channel"}`;
    return MANUAL_KIND_LABELS[row.kind] ?? SYSTEM_KIND_LABELS[row.kind] ?? row.kind;
}

/** Short / Over / Balanced for a counted-minus-expected difference. */
export function shortOver(variance) {
    if (variance === null || variance === undefined) return null;
    const v = Math.round(Number(variance) * 100) / 100;
    if (v === 0) return { label: "Balanced", amount: 0, tone: "ok" };
    return v < 0 ? { label: "Short", amount: -v, tone: "short" } : { label: "Over", amount: v, tone: "over" };
}

export const TONE_CLASSES = { ok: "text-green-700", short: "text-red-700", over: "text-amber-700" };
