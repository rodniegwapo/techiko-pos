<script setup>
import LeftSidebarWrapper from "@/Components/sidebar/leftWrapper.vue";
import LocationBadge from "@/Components/LocationBadge.vue";
import InquiryChatWidget from "@/Components/InquiryChatWidget.vue";
import Terminal from "@/Components/Terminal.vue";
import ImpersonationBanner from "@/Components/ImpersonationBanner.vue";

import { onMounted, onUnmounted, ref, watch, provide } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import {
    UserOutlined,
    VideoCameraOutlined,
    UploadOutlined,
} from "@ant-design/icons-vue";
import { IconMenu2 } from "@tabler/icons-vue";
import { useMediaQuery } from "@vueuse/core";
import { useAuth } from "@/Composables/useAuth";
import { useSidebar } from "@/Composables/useSidebar";

const { user } = useAuth();
const page = usePage();
const { toggleMobileDrawer, closeMobileDrawer } = useSidebar();
const isMdUp = useMediaQuery("(min-width: 768px)");
watch(isMdUp, (matches) => {
    if (matches) {
        closeMobileDrawer();
    }
});

watch(() => page.url, () => {
    closeMobileDrawer();
});

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;

const staffInboxBadge = ref(0);
let staffInboxFallbackTimer = null;

function syncStaffInboxFromProps() {
    if (user.value?.is_super_user) {
        staffInboxBadge.value = Number(page.props.inquiryUnreadCount) || 0;
    } else {
        staffInboxBadge.value = 0;
    }
}

watch(
    () => [page.props.inquiryUnreadCount, user.value?.is_super_user],
    () => {
        syncStaffInboxFromProps();
    },
    { immediate: true },
);

provide("inquiryUnreadCount", staffInboxBadge);

onUnmounted(() => {
    if (pusherKey && window.Echo) {
        try {
            window.Echo.leave("private-staff-inbox");
        } catch {
            // ignore
        }
    }
    if (staffInboxFallbackTimer) {
        clearInterval(staffInboxFallbackTimer);
    }
});

onMounted(() => {
    if (!user.value?.is_super_user) {
        return;
    }
    if (pusherKey && window.Echo) {
        try {
            window.Echo.private("staff-inbox").listen(".inbox.badge", (e) => {
                if (e.unread_conversation_count != null) {
                    staffInboxBadge.value = e.unread_conversation_count;
                } else {
                    router.reload({
                        only: ["inquiryUnreadCount"],
                        preserveScroll: true,
                    });
                }
            });
        } catch {
            // ignore
        }
    } else {
        staffInboxFallbackTimer = setInterval(
            () => {
                router.reload({
                    only: ["inquiryUnreadCount"],
                    preserveScroll: true,
                });
            },
            60_000,
        );
    }
});

const selectedKeys = ref(["1"]);
const collapsed = ref(false);

const terminalModal = ref(false);
onMounted(() => {
    let deviceId = localStorage.getItem("device_id");
    if (deviceId) {
        return (terminalModal.value = false);
    }

    return (terminalModal.value = true);
});
</script>

<template>
    <!-- Impersonation Banner (shown at the very top) -->
    <ImpersonationBanner />

    <a-layout
        class="relative bg-dots-darker bg-center bg-gray-200 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white"
    >
        <!-- <terminal /> -->
        <left-sidebar-wrapper :user="user" />

        <a-layout-content class="flex min-h-screen flex-1 flex-col">
            <div
                class="sticky top-0 z-[60] flex shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-3 py-2.5 dark:border-gray-700 dark:bg-gray-900 md:hidden"
            >
                <button
                    type="button"
                    class="inline-flex rounded-md p-2 text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800"
                    aria-label="Open menu"
                    @click="toggleMobileDrawer"
                >
                    <IconMenu2 :size="22" stroke="1.75" />
                </button>
                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                    >Menu</span
                >
            </div>
            <div
                class="max-w-7xl mx-auto w-full min-w-0 flex-1 p-3 sm:p-4 md:p-6 lg:overflow-auto md:overflow-auto sm:overflow-scroll bg-gray-200 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white"
            >
                <slot />
            </div>
            <div>
                <slot name="content-footer" />
            </div>
        </a-layout-content>

        <!-- Floating Location Badge -->
        <LocationBadge />

        <InquiryChatWidget v-if="user" />
    </a-layout>
</template>

<style>
.ant-menu-item-selected {
    @apply bg-green-500/20 text-green-500 !important;
}

.ant-menu-item.ant-menu-item-selected::after {
    border-right: 4px solid #014945 !important;
}
</style>
