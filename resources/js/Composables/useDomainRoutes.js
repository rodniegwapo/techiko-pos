import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useDomainRoutes() {
    const page = usePage();
    
    const isSuperUser = computed(() => !!page.props.auth?.user?.data?.is_super_user);
    const currentDomain = computed(() => page.props.domain);
    
    const getCurrentDomainFromUrl = () => {
        const currentPath = window.location.pathname;
        const domainMatch = currentPath.match(/\/domains\/([^\/]+)/);
        return domainMatch ? domainMatch[1] : null;
    };
    
    const isInDomainContext = computed(() => !!getCurrentDomainFromUrl());
    
    const getRoute = (routeName, params = {}) => {
        try {
            if (typeof window.route !== 'function') {
                console.warn('window.route is not a function');
                return "#";
            }
            
            const currentDomainSlug = getCurrentDomainFromUrl();
            
            // Super users can access both global and domain-specific routes
            if (isSuperUser.value) {
                // If we're in a domain context, maintain it
                if (isInDomainContext.value) {
                    const domainRouteName = `domains.${routeName}`;
                    return window.route(domainRouteName, { domain: currentDomainSlug, ...params });
                }
                // Otherwise use global route
                return window.route(routeName, params);
            } 
            // Regular users should use domain-specific routes
            else if (currentDomain.value || isInDomainContext.value) {
                const domainSlug = currentDomainSlug || currentDomain.value?.name_slug;
                const domainRouteName = `domains.${routeName}`;
                return window.route(domainRouteName, { domain: domainSlug, ...params });
            }
            
            console.warn('No route generated for:', routeName);
            return "#";
        } catch (error) {
            console.warn('Route generation error:', error, 'for route:', routeName);
            return "#";
        }
    };
    
    return {
        getRoute,
        isInDomainContext,
        currentDomain,
        isSuperUser
    };
}

