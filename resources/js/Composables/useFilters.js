import { ref, watch, computed, unref } from "vue";
import { usePage, router } from "@inertiajs/vue3"; // assuming Inertia.js

export const toLabel = (optionsRef) => (v) => {
    const raw = v && typeof v === "object" && "value" in v ? v.value : v;
    // A filter read back from the URL is a string, while its option's value may be a number.
    const opt = (optionsRef.value ?? []).find((o) => String(o.value) === String(raw));
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

    // Seed the filters the URL already carries, before the watcher below is set up: assigning them
    // after mount looks like the user changing a filter, and the reload that follows repeats the
    // request the server has already answered (and can cancel one still in flight).
    const queryString = (usePage().url ?? "").split("?")[1] ?? "";
    if (queryString) {
        const params = Object.fromEntries(new URLSearchParams(queryString));
        Object.keys(params).forEach((key) => {
            if (key in filters.value) {
                filters.value[key] = params[key];
                const config = configs.find((c) => c.key === key);
                if (config) {
                    config.ref.value = params[key];
                }
            }
        });
    }

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
