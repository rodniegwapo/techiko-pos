import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useStorage } from "@vueuse/core";

/**
 * Per-domain classic | coffeeshop preference (localStorage).
 * Key: sales_layout_mode_{domainSlug|default}
 */
export function useSalesLayoutMode() {
    const page = usePage();
    const domainSlug = computed(
        () => page.props.domain?.name_slug ?? page.props.domain?.nameSlug,
    );

    const salesLayoutMode = useStorage(
        `sales_layout_mode_${domainSlug.value || "default"}`,
        "classic",
    );

    const isCoffeeshopLayout = computed({
        get: () => salesLayoutMode.value === "coffeeshop",
        set: (checked) => {
            salesLayoutMode.value = checked ? "coffeeshop" : "classic";
        },
    });

    return {
        domainSlug,
        salesLayoutMode,
        isCoffeeshopLayout,
    };
}
