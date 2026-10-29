// What the business sells (Settings → Business type, Domain::BUSINESS_TYPES). It only presets the
// product form: the defaults a new product starts with and which sections show at first. Nothing is
// locked; "Show all fields" opens everything, and a product already using a section always shows it.
// It also tidies the sidebar: pages the type rarely needs are hidden there (still found by page
// search and their URL) unless the business turned on "Show all menu items".
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export const BUSINESS_TYPES = {
    retail: {
        label: "Retail store",
        description: "Items sold by the piece, pack or box: sari-sari, hardware, clothing, vape.",
        trackStock: true,
        soldType: "Piece",
        showModifiers: false,
        // Sidebar pages it rarely needs (hidden unless in use or "Show all menu items" is on).
        hiddenMenuKeys: ["dining-tables"],
    },
    food: {
        label: "Food & drinks",
        description: "Made to order: café, restaurant, milk tea. Add-ons like size or sugar level.",
        trackStock: false,
        soldType: "Piece",
        showModifiers: true,
        hiddenMenuKeys: [],
    },
    grocery: {
        label: "Grocery / sold by weight",
        description: "Wet market, rice, meat, liquids: priced per kilo or liter.",
        trackStock: true,
        soldType: "Kilo",
        showModifiers: false,
        hiddenMenuKeys: ["dining-tables"],
    },
    services: {
        label: "Services",
        description: "Salon, repair, laundry: no stock to count.",
        trackStock: false,
        soldType: "Piece",
        showModifiers: false,
        hiddenMenuKeys: ["dining-tables", "inventory-movements", "inventory-adjustments", "inventory-valuation", "purchasing"],
    },
    other: {
        label: "Other / mixed",
        description: "A bit of everything: every field shows, nothing is preset.",
        trackStock: true,
        soldType: null,
        showModifiers: true,
        hiddenMenuKeys: [],
    },
};

// Types open for picking (Domain::availableBusinessTypes); the rest show "Coming soon". Retail always is.
export function isBusinessTypeAvailable(key, available = usePage().props.availableBusinessTypes) {
    return key === "retail" || (available ?? []).includes(key);
}

export function useBusinessType() {
    const page = usePage();
    const type = computed(() => (BUSINESS_TYPES[page.props.businessType] ? page.props.businessType : "retail"));
    const preset = computed(() => BUSINESS_TYPES[type.value]);

    return { type, preset };
}
