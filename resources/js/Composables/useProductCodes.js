/**
 * Codes for stores without a barcode scanner. Uniqueness within the organization is still checked
 * by the server on save; on the rare clash, generating again gives a new code.
 */

const randomDigits = (count) =>
    Array.from({ length: count }, () => Math.floor(Math.random() * 10)).join("");

/** EAN-13 check digit for the first 12 digits. */
export function ean13CheckDigit(first12) {
    const sum = [...first12].reduce(
        (acc, d, i) => acc + Number(d) * (i % 2 === 0 ? 1 : 3),
        0,
    );
    return String((10 - (sum % 10)) % 10);
}

/**
 * A scannable EAN-13 in the GS1 in-store range (starts with "2"), which is reserved for a shop's
 * own codes and never clashes with a manufacturer's barcode.
 */
export function generateInternalBarcode() {
    const first12 = "2" + randomDigits(11);
    return first12 + ean13CheckDigit(first12);
}

/** Internal barcodes (13 digits starting with "2") are the shop's own, not a real product code. */
export function isInternalBarcode(code) {
    return /^2\d{12}$/.test(String(code ?? ""));
}

const SKU_CHARS = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"; // no 0/O or 1/I lookalikes

/** e.g. "Ice Pod Formula" → "IPF-4K7Q2"; falls back to "SKU-…" without a name. */
export function generateSku(name = "") {
    const words = String(name)
        .toUpperCase()
        .replace(/[^A-Z0-9 ]/g, " ")
        .split(/\s+/)
        .filter(Boolean);
    let prefix =
        words.length > 1
            ? words.slice(0, 3).map((w) => w[0]).join("")
            : (words[0] || "").slice(0, 3);
    if (!prefix) prefix = "SKU";
    const suffix = Array.from(
        { length: 5 },
        () => SKU_CHARS[Math.floor(Math.random() * SKU_CHARS.length)],
    ).join("");
    return `${prefix}-${suffix}`;
}
