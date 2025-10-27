import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useDomainRoutes() {
    const page = usePage();

    /*** === COMPUTED PROPERTIES === ***/
    const isSuperUser = computed(
        () => !!page.props.auth?.user?.data?.is_super_user
    );
    const currentDomain = computed(() => page.props.domain);

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

            console.log("🔹 Route Context:", {
                routeName,
                params,
                isSuperUser: isSuperUser.value,
                inDomain,
                domainSlug,
            });

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
        const route = window.route(name, params);
        console.log("✅ Generated route:", { name, params, route });
        return route;
    };

    /*** === RETURN API === ***/
    return {
        getRoute,
        getCurrentDomainFromUrl,
        isInDomainContext,
        currentDomain,
        isSuperUser,
    };
}
