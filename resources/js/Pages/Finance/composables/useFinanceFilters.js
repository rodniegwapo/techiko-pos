import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { dateRangePresets } from "@/Composables/useDateRangePresets";

/** Quick picks in the date filter that the server compares like with like (this month vs last month so far). */
const PRESET_PERIODS = { Today: "today", "This week": "week", "This month": "month", "This year": "year" };
const PERIOD_NAMES = Object.fromEntries(Object.entries(PRESET_PERIODS).map(([name, key]) => [key, name]));

/** The period query for a picked date range: a preset when it matches one, otherwise a custom range. */
export function periodQuery(range) {
    if (!Array.isArray(range) || !range[0] || !range[1]) return {};
    const start = dayjs(range[0]);
    const end = dayjs(range[1]);
    const presets = dateRangePresets();
    for (const [name, key] of Object.entries(PRESET_PERIODS)) {
        const [ps, pe] = presets[name];
        if (start.isSame(ps, "day") && end.isSame(pe, "day")) return { period: key };
    }
    return { period: "custom", start_date: start.format("YYYY-MM-DD"), end_date: end.format("YYYY-MM-DD") };
}

/** The query that keeps a page's period and location when linking to another Finance page. */
export function financeQuery(serverFilters, extra = {}) {
    const q = { period: serverFilters.key ?? "month" };
    if (serverFilters.key === "custom") {
        q.start_date = serverFilters.start_date;
        q.end_date = serverFilters.end_date;
    }
    if (serverFilters.location_id) q.location_id = serverFilters.location_id;
    return { ...q, ...extra };
}

/**
 * Filters for the Finance pages, the same way other pages filter: a FilterDropdown with a date
 * range (plus location and page-specific extras) and ActiveFilters tags under the title.
 *
 * `extra`: [{ key, label, param, options }] — `key` is the filter's name in the dropdown,
 * `param` the query parameter sent to the server (kept different from `key` on purpose, see
 * SalesHistory: useFilters seeds keys it finds in the URL as strings).
 */
export function useFinanceFilters({ routeName, serverFilters, locations = [], showLocation = true, extra = [] }) {
    // A getter, so the labels follow the filters the server sends back after each visit.
    const sf = typeof serverFilters === "function" ? serverFilters : () => serverFilters;
    const { getRoute } = useDomainRoutes();
    const { spinning } = useGlobalVariables();

    const dateRange = ref(
        sf().start_date ? [dayjs(sf().start_date), dayjs(sf().end_date)] : null,
    );
    const location = ref(sf().location_id ?? null);
    const extraRefs = extra.map((e) => ({ ...e, ref: ref(sf()[e.param] ?? null) }));

    const locationOptions = computed(() => (locations ?? []).map((l) => ({ value: l.id, label: l.name })));
    const withLocation = showLocation && (locations?.length ?? 0) > 1;

    const dateLabel = (v) => {
        if (!Array.isArray(v) || !v[0] || !v[1]) return null;
        const q = periodQuery(v);
        if (q.period && q.period !== "custom") return PERIOD_NAMES[q.period];
        return `${dayjs(v[0]).format("MMM D, YYYY")} – ${dayjs(v[1]).format("MMM D, YYYY")}`;
    };

    const load = (more = {}) => {
        const query = {
            ...periodQuery(dateRange.value),
            location_id: withLocation ? location.value || undefined : sf().location_id || undefined,
            ...Object.fromEntries(extraRefs.map((e) => [e.param, e.ref.value || undefined])),
            ...more,
        };
        router.get(getRoute(routeName), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (spinning.value = true),
            onFinish: () => (spinning.value = false),
        });
    };

    const configs = [
        { label: "Date", key: "date", ref: dateRange, getLabel: dateLabel },
        ...(withLocation ? [{ label: "Location", key: "location", ref: location, getLabel: toLabel(locationOptions) }] : []),
        ...extraRefs.map((e) => ({
            label: e.label,
            key: e.key,
            ref: e.ref,
            getLabel: toLabel(computed(() => (typeof e.options === "function" ? e.options() : e.options?.value ?? e.options ?? []))),
        })),
    ];

    const { filters, activeFilters, handleClearSelectedFilter } = useFilters({ getItems: () => load(), configs });

    const filtersConfig = computed(() => [
        { key: "date", label: "Date", type: "range" },
        ...(withLocation ? [{ key: "location", label: "Location", type: "select", options: locationOptions.value }] : []),
        ...extraRefs.map((e) => ({
            key: e.key,
            label: e.label,
            type: "select",
            options: typeof e.options === "function" ? e.options() : e.options?.value ?? e.options ?? [],
        })),
    ]);

    const clearAll = () => Object.keys(filters.value).forEach((k) => (filters.value[k] = null));

    const periodLabel = computed(() => {
        const name = PERIOD_NAMES[sf().key];
        const range = `${dayjs(sf().start_date).format("MMM D, YYYY")} – ${dayjs(sf().end_date).format("MMM D, YYYY")}`;
        return name ? `${name} (${range})` : range;
    });
    const previousLabel = computed(
        () =>
            `${dayjs(sf().previous_start_date).format("MMM D, YYYY")} – ${dayjs(sf().previous_end_date).format("MMM D, YYYY")}`,
    );

    return { filters, filtersConfig, activeFilters, handleClearSelectedFilter, clearAll, load, spinning, periodLabel, previousLabel };
}
