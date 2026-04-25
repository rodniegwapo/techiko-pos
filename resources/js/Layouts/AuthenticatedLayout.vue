<script setup>
import LeftMenu from "@/Components/sidebar/leftMenu.vue";
import LeftSidebarWrapper from "@/Components/sidebar/leftWrapper.vue";
import LeftAccountSettings from "@/Components/sidebar/leftAccountSettings.vue";
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
import { useAuth } from "@/Composables/useAuth";
import { useSidebar } from "@/Composables/useSidebar";

const { user } = useAuth();
const { isCollapsed } = useSidebar();
const page = usePage();
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
        <left-sidebar-wrapper>
            <!-- menu -->
            <left-menu />

            <!-- account-settings -->
            <left-account-settings
                :user="user"
                :leftSidebatdCollapsed="isCollapsed"
            />
        </left-sidebar-wrapper>

        <a-layout-content>
            <div
                class="max-w-7xl mx-auto p-6 lg:overflow-auto md:overflow-auto sm:overflow-scroll bg-gray-200 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white"
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
