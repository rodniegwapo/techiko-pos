// Quantities: whole numbers for things sold by the piece, up to three decimals for things sold by
// weight or volume (1.5 kg, 0.75 L). The server decides which a product is (allows_decimal).

export const QUANTITY_DECIMALS = 3;

/** A quantity read from anywhere (string, number, null) as a number with at most 3 decimals. */
export function toQuantity(value) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? Math.round(n * 1000) / 1000 : 0;
}

/** Sold by weight or volume: parts of a unit are allowed. */
export function allowsDecimal(item) {
    return !!(item?.allows_decimal ?? item?.product?.allows_decimal);
}

/** How much + and − change a cart line: a quarter for weighed items, one otherwise. */
export function quantityStep(item) {
    return allowsDecimal(item) ? 0.25 : 1;
}

/** "1.5 kg", "2 L", "3" (pieces show no unit, as before). */
export function formatQuantity(value, item) {
    const q = toQuantity(value);
    const text = Number.isInteger(q) ? String(q) : String(q).replace(/0+$/, "");
    const unit = item?.quantity_unit ?? item?.product?.quantity_unit;

    return allowsDecimal(item) && unit ? `${text} ${unit}` : text;
}
