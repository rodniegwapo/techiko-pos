import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useDomainRoutes() {
    const page = usePage();

    /*** === COMPUTED PROPERTIES === ***/
    const isSuperUser = computed(
        () => !!page.props.auth?.user?.data?.is_super_user
    );
    const currentDomain = computed(() => page.props.currentDomain);

    /*** === HELPERS === ***/
    const getCurrentDomainFromUrl = () => {
        const match = window.location.pathname.match(/\/domains\/([^\/]+)/);
        return match ? match[1] : null;
    };

    const isInDomainContext = computed(() => !!getCurrentDomainFromUrl());

    /*** === ROUTE GENERATION === ***/
    const getRoute = (routeName, params = {}) => {
        try {
            // Ensure Ziggy is available
            if (typeof window.route !== "function") {
                console.warn("⚠️ Ziggy route function not available");
                return "#";
            }

            const domainSlug =
                getCurrentDomainFromUrl() || currentDomain.value?.name_slug;
            const inDomain = isInDomainContext.value || !!currentDomain.value;
            const domainRouteName = `domains.${routeName}`;

            // === SUPER USER LOGIC ===
            if (isSuperUser.value) {
                if (inDomain && domainSlug) {
                    return buildRoute(domainRouteName, {
                        domain: domainSlug,
                        ...params,
                    });
                }
                return buildRoute(routeName, params);
            }

            // === REGULAR USER LOGIC ===
            if (inDomain && domainSlug) {
                return buildRoute(domainRouteName, {
                    domain: domainSlug,
                    ...params,
                });
            }

            console.warn("⚠️ No route generated for:", routeName);
            return "#";
        } catch (error) {
            console.error(
                "❌ Route generation error:",
                error,
                "for route:",
                routeName
            );
            return "#";
        }
    };

    /*** === INTERNAL BUILDER === ***/
    const buildRoute = (name, params) => {
        try {
            const route = window.route(name, params);
            // Check if route is valid (not undefined, null, or empty string)
            if (!route || route === '' || route === '#') {
                console.warn(`⚠️ Route '${name}' returned invalid value:`, route);
                return '#';
            }
            return route;
        } catch (error) {
            console.error(`❌ Error building route '${name}':`, error);
            return '#';
        }
    };

    /**
     * Read location_id from Inertia page.url (path + query).
     * Keeps the selected store when navigating under /domains/{slug}/...
     */
    const getLocationQueryFromPage = () => {
        const raw = page.url ?? "";
        const idx = raw.indexOf("?");
        if (idx === -1) {
            return {};
        }
        const id = new URLSearchParams(raw.slice(idx + 1)).get("location_id");
        if (id === null || id === "") {
            return {};
        }
        return { location_id: id };
    };

    /** Append ?location_id= when current page URL includes it (GET / form action hrefs). */
    const hrefWithPreservedLocationId = (path) => {
        const q = getLocationQueryFromPage();
        if (
            !path ||
            path === "#" ||
            Object.keys(q).length === 0
        ) {
            return path;
        }
        const sep = path.includes("?") ? "&" : "?";
        return `${path}${sep}${new URLSearchParams(q).toString()}`;
    };

    /*** === RETURN API === ***/
    return {
        getRoute,
        getCurrentDomainFromUrl,
        isInDomainContext,
        currentDomain,
        isSuperUser,
        getLocationQueryFromPage,
        hrefWithPreservedLocationId,
    };
}
