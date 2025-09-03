import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

export function useAuth() {
    return {
        user: computed(() => usePage().props.auth.user.data)
    }
}
