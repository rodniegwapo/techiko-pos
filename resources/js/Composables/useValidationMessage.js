/**
 * Normalize Laravel / Inertia validation errors for Ant Design Vue :help bindings.
 * Laravel returns either a string or string[] per field.
 *
 * @param {Record<string, string|string[]|unknown>|null|undefined} errors
 * @param {string} fieldKey
 * @returns {string}
 */
export function validationMessage(errors, fieldKey) {
    if (!errors || fieldKey == null) {
        return "";
    }
    const raw = errors[fieldKey];
    if (raw == null || raw === "") {
        return "";
    }
    if (Array.isArray(raw)) {
        const first = raw[0];
        return first != null ? String(first) : "";
    }
    return String(raw);
}

/**
 * @param {Record<string, string|string[]|unknown>|null|undefined} errors
 * @param {string} fieldKey
 * @returns {boolean}
 */
export function validationHasError(errors, fieldKey) {
    return validationMessage(errors, fieldKey) !== "";
}

function humanFieldLabel(key) {
    return key
        .replace(/_/g, " ")
        .replace(/\bSKU\b/, "SKU")
        .trim();
}

/**
 * Short notice for toast/notification when validation fails.
 *
 * @param {Record<string, string|string[]|unknown>|null|undefined} errors
 * @returns {string}
 */
export function validationSummaryNotice(errors) {
    if (!errors || typeof errors !== "object") {
        return "Please check the form and try again.";
    }
    const keys = Object.keys(errors).filter((k) => validationHasError(errors, k));
    if (!keys.length) {
        return "Please check the form and try again.";
    }
    const titled = keys
        .slice(0, 5)
        .map((k) =>
            /^[a-z_]+$/i.test(k) ? humanFieldLabel(k) : String(k),
        )
        .join(", ");
    const firstMsg = validationMessage(errors, keys[0]);
    const suffix = firstMsg ? ` ${firstMsg}` : "";
    return `Please fix: ${titled}.${suffix}`;
}
