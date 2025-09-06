import { ref, watch, computed, unref } from "vue";

// helper for mapping values to labels
export const toLabel = (optionsRef) => (v) => {
    const raw = v && typeof v === "object" && "value" in v ? v.value : v;
    const opt = (optionsRef.value ?? []).find((o) => o.value === raw);
    return opt?.label ?? null;
};

/**
 * useFilters composable
 *
 * @param {Object} options
 * @param {Array} options.configs - filter definitions
 * @param {Function|Ref<Function>} options.getItems - callback or reactive callback
 *
 * Example config:
 * [
 *   { key: "status", ref: selectedStatus, getLabel: toLabel(statusOptions) },
 *   { key: "room", ref: selectedRoom, getLabel: toLabel(roomOptions) },
 *   { key: "range", ref: selectedRange, getLabel: (v) => v ? `${v[0]} - ${v[1]}` : null }
 * ]
 */
export function useFilters({ configs, getItems }) {
    const filters = ref({});
    const getItemsRef = ref(getItems); // store callback as ref (dynamic)

    // call latest callback
    const callGetItems = (args) => {
        const fn = unref(getItemsRef.value);
        if (typeof fn === "function") fn(args);
    };

    // initialize filters with current ref values
    configs.forEach(({ key, ref }) => {
        filters.value[key] = ref.value ?? null;
    });

    // keep refs in sync with filters
    watch(
        filters,
        (newVal) => {
            configs.forEach(({ key, ref }) => {
                ref.value = newVal?.[key] ?? null;
            });
            callGetItems({ ...newVal }); // always use latest function
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

    const activeFilters = computed(() =>
        configs
            .map(({ key, ref, getLabel }, idx) => ({
                key,
                value: getLabel ? getLabel(ref.value) : ref.value,
                reset: filtersWithReset[idx].reset,
            }))
            .filter((f) => f.value !== null && f.value !== undefined)
    );

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
