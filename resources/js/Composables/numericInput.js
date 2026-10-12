// Keep money and reference-number boxes to numbers: letters, "e", "+" and "-" can't be typed or pasted.

const EDIT_KEYS = [
    "Backspace",
    "Delete",
    "Tab",
    "Enter",
    "Escape",
    "ArrowLeft",
    "ArrowRight",
    "ArrowUp",
    "ArrowDown",
    "Home",
    "End",
];

/** keydown handler: digits (and one "." when `decimal`), plus editing keys and shortcuts. */
export function numberKeysOnly(event, { decimal = true } = {}) {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (EDIT_KEYS.includes(event.key) || /^\d$/.test(event.key)) return;
    if (decimal && event.key === "." && !String(event.target?.value ?? "").includes(".")) return;
    event.preventDefault();
}

/** paste handler: refuses text that isn't a plain number (or digits, without `decimal`). */
export function numberPasteOnly(event, { decimal = true } = {}) {
    const text = event.clipboardData?.getData("text") ?? "";
    const ok = decimal ? /^\d*\.?\d*$/.test(text.trim()) : /^\d*$/.test(text.trim());
    if (!ok) event.preventDefault();
}

/** Drops everything but digits (e.g. for a reference number set from code or autofill). */
export const digitsOnly = (value) => String(value ?? "").replace(/\D+/g, "");
