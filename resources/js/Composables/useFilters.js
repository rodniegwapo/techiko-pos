import { ref, watch, computed, unref, onMounted } from "vue";
import { usePage, router } from "@inertiajs/vue3"; // assuming Inertia.js

export const toLabel = (optionsRef) => (v) => {
    const raw = v && typeof v === "object" && "value" in v ? v.value : v;
    const opt = (optionsRef.value ?? []).find((o) => o.value === raw);
    return opt?.label ?? null;
};

export function useFilters({ configs, getItems }) {
    const filters = ref({});
    const getItemsRef = ref(getItems);

    const callGetItems = (args) => {
        const fn = unref(getItemsRef.value);
        if (typeof fn === "function") fn(args);
    };

    // Initialize filters with refs
    configs.forEach(({ key, ref }) => {
        filters.value[key] = ref.value ?? null;
    });

    // **Update filters from query parameters on mount**
 onMounted(() => {
    const url = usePage().url ?? ""; // use Inertia's url
    const queryString = url.split("?")[1] ?? "";
    if (queryString) {
        const params = Object.fromEntries(new URLSearchParams(queryString));
        Object.keys(params).forEach((key) => {
            if (key in filters.value) {
                filters.value[key] = params[key];
            }
        });
    }
});
    // Sync filters with refs and call getItems
    watch(
        filters,
        (newVal) => {
            configs.forEach(({ key, ref }) => {
                ref.value = newVal?.[key] ?? null;
            });
            callGetItems({ ...newVal });
        },
        { deep: true }
    );

    const filtersWithReset = configs.map((f) => ({
        ...f,
        value: f.ref,
        reset: () => {
            delete filters.value[f.key];
            f.ref.value = null;
        },
    }));

    const activeFilters = computed(() => {
        return configs
            .map(({ label, key, ref, getLabel }, idx) => ({
                label,
                key,
                value: getLabel ? getLabel(ref.value) : ref.value,
                reset: filtersWithReset[idx].reset,
            }))
            .filter((f) => f.value !== null && f.value !== undefined);
    });

    const handleClearSelectedFilter = (key) => {
        const filter = activeFilters.value.find((f) => f.key === key);
        if (filter?.reset) filter.reset();
    };

    return {
        filters,
        filtersWithReset,
        activeFilters,
        handleClearSelectedFilter,
    };
}
