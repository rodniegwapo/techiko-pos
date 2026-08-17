import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";

/**
 * Resolved auth user payload (same shape as page.props.auth.user.data when logged in).
 * Null when guest or props are not yet available (e.g. partial visits).
 */
export function useAuth() {
    const page = usePage();
    return {
        user: computed(() => page.props.auth?.user?.data ?? null),
    };
}
