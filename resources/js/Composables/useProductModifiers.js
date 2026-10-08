/**
 * Product modifiers (Size, Add-ons…) at the POS: the groups a product offers and the options picked
 * for a cart line. The server re-checks and re-prices every pick; this is for the screen and for
 * offline carts.
 */

/** The groups a product offers that have options to pick from. */
export function modifierGroupsOf(product) {
    return (product?.modifier_groups || []).filter(
        (g) => (g.active_modifiers || []).length > 0,
    );
}

export function hasModifiers(product) {
    return modifierGroupsOf(product).length > 0;
}

/** The same options in any order make the same line. */
export function modifierKey(ids) {
    const sorted = [...new Set((ids || []).map(Number))].sort((a, b) => a - b);
    return sorted.length ? sorted.join(",") : null;
}

/** Why a pick can't be added yet, or null. */
export function modifierProblem(product, pickedIds) {
    const picked = new Set((pickedIds || []).map(Number));
    for (const g of modifierGroupsOf(product)) {
        const count = g.active_modifiers.filter((m) => picked.has(m.id)).length;
        if (g.is_required && count === 0) return `Choose the ${g.name}.`;
        const max = g.selection === "single" ? 1 : g.max_select;
        if (max && count > max) {
            return max === 1 ? `Choose only one ${g.name}.` : `Choose up to ${max} ${g.name}.`;
        }
    }
    return null;
}

/**
 * The cart line for a product with these options: the options themselves (as the receipt shows them),
 * their key and the line's unit price.
 */
export function lineOptions(product, pickedIds, notes = "") {
    const picked = new Set((pickedIds || []).map(Number));
    const modifiers = [];
    for (const g of modifierGroupsOf(product)) {
        for (const m of g.active_modifiers) {
            if (picked.has(m.id)) {
                modifiers.push({
                    id: m.id,
                    group_name: g.name,
                    name: m.name,
                    price_delta: Number(m.price_delta) || 0,
                });
            }
        }
    }
    const delta = modifiers.reduce((s, m) => s + Math.round(m.price_delta * 100), 0);
    const trimmed = String(notes || "").trim();

    return {
        modifier_ids: modifiers.map((m) => m.id),
        modifier_key: modifierKey(modifiers.map((m) => m.id)),
        modifiers,
        notes: trimmed || null,
        unit_price: (Math.round((parseFloat(product.price) || 0) * 100) + delta) / 100,
    };
}

/** "Large, Extra shot" — the options of a cart line in one line. */
export function modifierSummary(line) {
    return (line?.modifiers || []).map((m) => m.name).join(", ");
}
