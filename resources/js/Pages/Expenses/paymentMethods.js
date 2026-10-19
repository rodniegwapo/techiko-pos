const LABELS = {
    cash_register: "Cash on hand (register)",
    bank: "Bank transfer",
    ewallet: "E-wallet",
    card: "Card",
    other: "Other",
};

export const paymentMethodLabel = (method) => LABELS[method] ?? method;

/** "Bank transfer · BDO Savings" when the account it was paid from is known. */
export const paidFromLabel = (method, accountName) =>
    accountName ? `${paymentMethodLabel(method)} · ${accountName}` : paymentMethodLabel(method);
