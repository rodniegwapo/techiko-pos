// Simplified + stable boolean version
import { usePage } from "@inertiajs/vue3";

export function usePermissionsV2() {
    const page = usePage();

    const hasPermission = (routeName) => {
        const user = page.props.auth?.user?.data;
        if (!user) return false;
        if (user.is_super_user) return true;
        return !!user.permissions?.some(
            (perm) => perm.route_name === routeName
        );
    };

    return { hasPermission };
}
