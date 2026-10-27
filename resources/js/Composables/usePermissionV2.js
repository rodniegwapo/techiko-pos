// Simplified + stable boolean version
import { usePage } from "@inertiajs/vue3";

export function usePermissionsV2() {
    const page = usePage();

    // Some pages open with another page's permission (RoutePermission::ALIASES on the server).
    const hasPermission = (routeName) => {
        const user = page.props.auth?.user?.data;
        if (!user) return false;
        if (user.is_super_user) return true;
        const wanted = page.props.routePermissionAliases?.[routeName] ?? [routeName];
        return !!user.permissions?.some(
            (perm) => wanted.includes(perm.route_name)
        );
    };

    return { hasPermission };
}
