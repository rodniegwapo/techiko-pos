// How a customer's loyalty tier is shown: the name and colour set in Tier Management (the
// customer's tier_info), falling back to the classic tiers when a record doesn't carry it.

const FALLBACK_COLORS = {
    bronze: "#CD7F32",
    silver: "#C0C0C0",
    gold: "#FFD700",
    platinum: "#E5E4E2",
};

export function tierColor(customer) {
    return customer?.tier_info?.color || FALLBACK_COLORS[customer?.tier] || FALLBACK_COLORS.bronze;
}

export function tierName(customer) {
    if (customer?.tier_info?.name) return customer.tier_info.name;
    const tier = customer?.tier || "bronze";
    return tier.charAt(0).toUpperCase() + tier.slice(1);
}
