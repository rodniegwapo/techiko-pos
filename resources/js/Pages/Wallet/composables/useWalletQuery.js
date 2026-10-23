import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/** The page URL's query string as an object. */
export function queryFromUrl(url) {
    if (!url || typeof url !== "string") return {};
    const idx = url.indexOf("?");
    return idx === -1 ? {} : Object.fromEntries(new URLSearchParams(url.slice(idx + 1)));
}

/**
 * The store and business date the wallet pages are looking at. They stay put when moving
 * between the pages or filtering.
 */
export function useWalletQuery(activeLocation = () => null) {
    const page = usePage();
    const { getRoute } = useDomainRoutes();
    const todayYmd = new Date().toISOString().slice(0, 10);

    const query = computed(() => queryFromUrl(page.url));

    const locationId = computed(() => {
        const q = query.value.location_id;
        if (q && /^[0-9]+$/.test(String(q))) return Number(q);
        return activeLocation()?.id ?? page.props?.currentLocation?.id ?? null;
    });

    const keepQuery = computed(() =>
        Object.fromEntries(["location_id", "business_date"].filter((k) => query.value[k]).map((k) => [k, query.value[k]])),
    );

    function visit(routeName, extra = {}, params = {}, options = {}) {
        router.get(getRoute(routeName, params), { ...keepQuery.value, ...extra }, { preserveScroll: true, ...options });
    }

    return { query, locationId, keepQuery, visit, todayYmd };
}
