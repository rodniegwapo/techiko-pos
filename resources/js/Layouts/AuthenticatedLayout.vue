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

const props = defineProps({
    // The page fills the screen exactly and scrolls inside its own panels (e.g. the Sales screen);
    // the store switcher is pinned in the corner so it takes no room.
    fitViewport: { type: Boolean, default: false },
});

const { user } = useAuth();
const page = usePage();
const { toggleMobileDrawer, closeMobileDrawer } = useSidebar();
const isMdUp = useMediaQuery("(min-width: 768px)");
watch(isMdUp, (matches) => {
    if (matches) {
        closeMobileDrawer();
    }
});

watch(
    () => page.url,
    () => {
        closeMobileDrawer();
    },
);

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
        staffInboxFallbackTimer = setInterval(() => {
            router.reload({
                only: ["inquiryUnreadCount"],
                preserveScroll: true,
            });
        }, 60_000);
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

let currentMobileTitle = ref("");
onMounted(() => {
    currentMobileTitle.value =
        localStorage.getItem("selectedMenuForMobile") ?? "Menu";
});
</script>

<template>
    <div
        :class="
            props.fitViewport ? 'flex h-[100dvh] flex-col overflow-hidden' : ''
        "
    >
    <!-- Impersonation Banner (shown at the very top) -->
    <ImpersonationBanner :class="{ 'shrink-0': props.fitViewport }" />

    <a-layout
        class="relative bg-dots-darker bg-center bg-gray-200 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white"
        :class="{ 'min-h-0 flex-1 overflow-hidden': props.fitViewport }"
    >
        <!-- <terminal /> -->
        <left-sidebar-wrapper :user="user" />

        <a-layout-content
            class="flex flex-1 flex-col"
            :class="
                props.fitViewport
                    ? 'h-full min-h-0 overflow-hidden'
                    : 'min-h-screen'
            "
        >
            <div
                class="sticky top-0 z-[60] flex shrink-0 items-center gap-3 border-b border-gray-300 bg-white px-3 py-2.5 shadow-sm md:hidden"
            >
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white p-2 text-gray-900 shadow-sm hover:bg-gray-50 active:bg-gray-100"
                    aria-label="Open menu"
                    @click="toggleMobileDrawer"
                >
                    <IconMenu2 :size="24" :stroke-width="2" color="#111827" />
                </button>
                <span class="text-sm font-semibold text-gray-900">{{
                    currentMobileTitle
                }}</span>
                <LocationBadge
                    v-if="props.fitViewport"
                    pinned
                    class="ml-auto"
                />
            </div>
            <div
                class="max-w-7xl mx-auto w-full min-w-0 flex-1 p-3 sm:p-4 md:p-6 bg-gray-200 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white"
                :class="
                    props.fitViewport
                        ? 'relative flex min-h-0 flex-col overflow-hidden'
                        : 'lg:overflow-auto md:overflow-auto sm:overflow-scroll'
                "
            >
                <!-- Fitted pages: the store switcher is pinned in the corner (beside the page title) so it takes no room. -->
                <LocationBadge
                    v-if="props.fitViewport"
                    pinned
                    class="absolute right-3 top-3 z-50 hidden sm:right-4 sm:top-4 md:right-6 md:top-6 md:block"
                />
                <!-- Store switcher sits above the page so it never covers header actions -->
                <LocationBadge v-else />
                <!-- Why the user was sent here, e.g. away from a warehouse's sales screen -->
                <a-alert
                    v-if="page.props.flash?.notice"
                    :message="page.props.flash.notice"
                    type="info"
                    show-icon
                    closable
                    class="mb-3"
                    :class="{ 'shrink-0': props.fitViewport }"
                />
                <div
                    v-if="props.fitViewport"
                    class="flex min-h-0 flex-1 flex-col"
                >
                    <slot />
                </div>
                <slot v-else />
            </div>
            <div :class="{ 'shrink-0': props.fitViewport }">
                <slot name="content-footer" />
            </div>
        </a-layout-content>

        <InquiryChatWidget v-if="user" />
    </a-layout>
    </div>
</template>

<style>
.ant-menu-item-selected {
    @apply bg-green-500/20 text-green-500 !important;
}

.ant-menu-item.ant-menu-item-selected::after {
    border-right: 4px solid #014945 !important;
}
</style>
