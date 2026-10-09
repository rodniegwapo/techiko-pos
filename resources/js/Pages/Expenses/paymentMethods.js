const LABELS = {
    cash_register: "Cash register",
    bank: "Bank transfer",
    ewallet: "E-wallet",
    card: "Card",
    other: "Other",
};

export const paymentMethodLabel = (method) => LABELS[method] ?? method;
