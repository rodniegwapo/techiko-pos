// What the business sells (Settings → Business type, Domain::BUSINESS_TYPES). It only presets the
// product form: the defaults a new product starts with and which sections show at first. Nothing is
// locked; "Show all fields" opens everything, and a product already using a section always shows it.
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export const BUSINESS_TYPES = {
    retail: {
        label: "Retail store",
        description: "Items sold by the piece, pack or box: sari-sari, hardware, clothing, vape.",
        trackStock: true,
        soldType: "Piece",
        showModifiers: false,
    },
    food: {
        label: "Food & drinks",
        description: "Made to order: café, restaurant, milk tea. Add-ons like size or sugar level.",
        trackStock: false,
        soldType: "Piece",
        showModifiers: true,
    },
    grocery: {
        label: "Grocery / sold by weight",
        description: "Wet market, rice, meat, liquids: priced per kilo or liter.",
        trackStock: true,
        soldType: "Kilo",
        showModifiers: false,
    },
    services: {
        label: "Services",
        description: "Salon, repair, laundry: no stock to count.",
        trackStock: false,
        soldType: "Piece",
        showModifiers: false,
    },
    other: {
        label: "Other / mixed",
        description: "A bit of everything: every field shows, nothing is preset.",
        trackStock: true,
        soldType: null,
        showModifiers: true,
    },
};

export function useBusinessType() {
    const page = usePage();
    const type = computed(() => (BUSINESS_TYPES[page.props.businessType] ? page.props.businessType : "retail"));
    const preset = computed(() => BUSINESS_TYPES[type.value]);

    return { type, preset };
}
