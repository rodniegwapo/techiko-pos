/**
 * The one place for the plain words the wallet pages use. A "payment channel" is a store's card
 * terminal, e-wallet or bank; the "cash drawer" is the store's physical cash.
 */

export const CHANNEL_KIND_LABELS = { card: "Card terminal", ewallet: "E-wallet", bank: "Bank" };

/** Where money is, grouped: the cash drawer, e-wallets, then banks and card terminals. */
export const PLACE_GROUPS = [
    { key: "cash", label: "Cash", kinds: ["drawer"] },
    { key: "ewallet", label: "E-wallets", kinds: ["ewallet"] },
    { key: "bank", label: "Banks & card terminals", kinds: ["bank", "card"] },
];

/** Places ({ kind, balance }) by group, each with its subtotal; empty groups left out. */
export function groupPlaces(places) {
    return PLACE_GROUPS.map((g) => {
        const rows = places.filter((p) => g.kinds.includes(p.kind));
        return { ...g, places: rows, subtotal: Math.round(rows.reduce((sum, p) => sum + (Number(p.balance) || 0), 0) * 100) / 100 };
    }).filter((g) => g.places.length);
}

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
    owner_transfer_in: "Cash from another store",
    transfer_out: "Transferred out",
    transfer_in: "Transferred in",
    to_cash_on_hand: "To Cash on hand",
    from_cash_on_hand: "From Cash on hand",
};

/** Cash on hand's own lines: the business's cash the owner holds. */
export const CASH_ON_HAND_KIND_LABELS = {
    collected: "Collected",
    to_store: "Moved to a store",
    owner_use: "Owner used it",
    adjustment: "Correction",
};

/** Lines Today's drawer writes itself; the server marks them in `notes`. */
export const AUTO_NOTE_LABELS = {
    AUTO_CC_OPENING: "Opening cash",
    AUTO_CC_COUNTED_VARIANCE: "Count difference",
    AUTO_CC_ENDSHIFT_CASHOUT: "Cash out to Cash on hand at end of day",
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

/** Which count a count was: an optional spot check, the store's final count, or a cashier's own. */
export const COUNT_KIND_LABELS = {
    spot_check: "Spot check",
    end_of_day: "End of day",
    shift: "Cashier's shift",
};

/** A cashier's shift: open, ended (count matched), waiting for a manager, or approved. */
export const SHIFT_STATUS = {
    open: { label: "Open", color: "green" },
    closed: { label: "Ended", color: "blue" },
    needs_review: { label: "Needs review", color: "orange" },
    approved: { label: "Approved", color: "default" },
};
