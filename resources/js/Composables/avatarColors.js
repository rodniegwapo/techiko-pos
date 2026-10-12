// Colours for initials avatars: the saved hex when it is valid, otherwise one picked from the name,
// with text that stays readable on it.

const PALETTE = [
    "#f56565", "#ed8936", "#d69e2e", "#38a169", "#319795",
    "#3182ce", "#5a67d8", "#805ad5", "#d53f8c", "#718096",
];

/** "#ABC", "abc", "#aabbcc" → "#aabbcc"; anything else → null. */
export function normalizeHex(value) {
    const hex = String(value ?? "").trim().replace(/^#/, "").toLowerCase();
    if (/^[0-9a-f]{3}$/.test(hex)) {
        return `#${hex.split("").map((c) => c + c).join("")}`;
    }
    return /^[0-9a-f]{6}$/.test(hex) ? `#${hex}` : null;
}

export function nameColor(name) {
    const text = String(name ?? "");
    let sum = 0;
    for (const ch of text) sum += ch.charCodeAt(0);
    return PALETTE[sum % PALETTE.length];
}

/** Dark text on light backgrounds, white on dark ones. */
export function readableTextColor(hex) {
    const h = normalizeHex(hex) ?? "#718096";
    const r = parseInt(h.slice(1, 3), 16);
    const g = parseInt(h.slice(3, 5), 16);
    const b = parseInt(h.slice(5, 7), 16);
    return (r * 299 + g * 587 + b * 114) / 1000 >= 150 ? "#1f2937" : "#ffffff";
}

export function initials(name) {
    const words = String(name ?? "").trim().split(/\s+/).filter(Boolean);
    if (!words.length) return "?";
    return (words[0][0] + (words.length > 1 ? words[words.length - 1][0] : "")).toUpperCase();
}

/** Background and text colour for an avatar, preferring a saved hex. */
export function avatarStyle(name, savedHex = null) {
    const backgroundColor = normalizeHex(savedHex) ?? nameColor(name);
    return { backgroundColor, color: readableTextColor(backgroundColor) };
}
