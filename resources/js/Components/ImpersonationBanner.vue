<script setup>
import { computed } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import { IconUserOff, IconAlertTriangle } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";

const page = usePage();

const impersonation = computed(() => page.props.impersonation);

const isImpersonating = computed(() => {
    return impersonation.value && impersonation.value.is_impersonating;
});

const impersonatedUser = computed(() => {
    return impersonation.value?.impersonated_user;
});

const impersonator = computed(() => {
    return impersonation.value?.impersonator;
});

const stopImpersonating = () => {
    router.post(
        "/stop-impersonating",
        {},
        {
            preserveState: false,
            preserveScroll: false,
            onSuccess: () => {
                notification.success({
                    message: "Impersonation Stopped",
                    description: `You are now back as ${impersonator.value?.name}`,
                });
            },
            onError: (errors) => {
                notification.error({
                    message: "Error",
                    description:
                        errors.message || "Failed to stop impersonating",
                });
            },
        }
    );
};
</script>

<template>
    <div
        v-if="isImpersonating"
        class="bg-gradient-to-r from-orange-500 to-amber-500 text-white shadow-lg border-b-4 border-orange-600"
    >
        <div
            class="container mx-auto px-4 py-3 flex items-center justify-between"
        >
            <div class="flex items-center gap-3">
                <IconAlertTriangle
                    :size="24"
                    class="animate-pulse flex-shrink-0"
                />
                <div class="flex flex-col sm:flex-row sm:items-center sm:gap-2">
                    <span class="font-bold text-sm sm:text-base">
                        Impersonation Mode Active
                    </span>
                    <span class="text-xs sm:text-sm opacity-90">
                        You are viewing the system as
                        <strong class="font-semibold">{{
                            impersonatedUser?.name
                        }}</strong>
                        ({{ impersonatedUser?.email }})
                    </span>
                </div>
            </div>

            <a-button
                type="default"
                size="large"
                class="flex items-center gap-2 bg-white text-orange-600 hover:bg-gray-100 hover:text-orange-700 border-2 border-white font-semibold shadow-md hover:shadow-lg transition-all duration-200"
                @click="stopImpersonating"
            >
                <IconUserOff :size="20" />
                <span class="hidden sm:inline">Stop Impersonating</span>
                <span class="sm:hidden">Exit</span>
            </a-button>
        </div>
    </div>
</template>

<style scoped>
@keyframes pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
