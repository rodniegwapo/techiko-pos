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
            
            console.log('Generating route:', {
                routeName,
                currentDomainSlug,
                isInDomainContext: isInDomainContext.value,
                isSuperUser: isSuperUser.value,
                currentDomain: currentDomain.value
            });
            
            // Super users can access both global and domain-specific routes
            if (isSuperUser.value) {
                // If we're in a domain context, maintain it
                if (isInDomainContext.value) {
                    const domainRouteName = `domains.${routeName}`;
                    
                    // For user-specific routes, ensure we have the user parameter
                    if (routeName.startsWith('users.') && params.user) {
                        const route = window.route(domainRouteName, { domain: currentDomainSlug, user: params.user, ...params });
                        console.log('Super user user-specific route in domain context:', { domainRouteName, domainSlug: currentDomainSlug, userId: params.user, route });
                        return route;
                    }
                    
                    const route = window.route(domainRouteName, { domain: currentDomainSlug, ...params });
                    console.log('Super user in domain context:', { domainRouteName, route });
                    return route;
                }
                // Otherwise use global route
                const route = window.route(routeName, params);
                console.log('Super user in global context:', { routeName, route });
                return route;
            } 
            // Regular users should use domain-specific routes
            else if (currentDomain.value || isInDomainContext.value) {
                const domainSlug = currentDomainSlug || currentDomain.value?.name_slug;
                const domainRouteName = `domains.${routeName}`;
                
                // For user-specific routes, ensure we have the user parameter
                if (routeName.startsWith('users.') && params.user) {
                    console.log('Processing user-specific route:', { routeName, params, domainSlug });
                    const route = window.route(domainRouteName, { domain: domainSlug, user: params.user, ...params });
                    console.log('User-specific route in domain context:', { domainRouteName, domainSlug, userId: params.user, route });
                    return route;
                }
                
                const route = window.route(domainRouteName, { domain: domainSlug, ...params });
                console.log('Regular user in domain context:', { domainRouteName, domainSlug, route });
                return route;
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

