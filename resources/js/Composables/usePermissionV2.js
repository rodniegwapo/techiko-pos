import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";

export function usePermissionsV2(routeName) {
    const page = usePage();

    // Current user and permissions from backend (names align with route names in seeder)
    const currentUser = computed(() => page.props.auth?.user?.data);

    if (currentUser.value.is_super_user) {
        return true;
    }

    const find = currentUser.value?.permissions.find(
        (item) => item.name == routeName
    );

    if (!find) return false;

    return true;
}
