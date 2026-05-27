<script setup>
import TechikoLogo from "@/Components/TechikoLogo.vue";
import TLogo from "@/Components/TLogo.vue";
import LeftMenu from "@/Components/sidebar/leftMenu.vue";
import LeftAccountSettings from "@/Components/sidebar/leftAccountSettings.vue";
import { IconLayoutSidebarLeftCollapse } from "@tabler/icons-vue";
import { useSidebar } from "@/Composables/useSidebar";

defineProps({
    impersonator: Boolean,
    user: {
        type: Object,
        default: null,
    },
});

const { isCollapsed, toggle, mobileDrawerOpen, closeMobileDrawer } =
    useSidebar();
</script>

<template>
    <!-- md+: fixed sidebar -->
    <div class="hidden md:block">
        <a-layout-sider
            :width="280"
            v-model:collapsed="isCollapsed"
            :trigger="null"
            theme="light"
            collapsible
            class="sticky top-0 z-50 h-screen"
        >
            <div class="flex relative w-full flex-col h-full">
                <div
                    class="text-white flex items-center justify-between px-6 py-4"
                    :class="
                        isCollapsed
                            ? 'flex-col-reverse items-center'
                            : 'space-x-2'
                    "
                >
                    <TLogo
                        v-if="isCollapsed"
                        style="margin-top: 28px !important"
                    />
                    <TechikoLogo v-if="!isCollapsed" :height="30" />
                    <a
                        role="button"
                        @click="
                            () => {
                                toggle();
                            }
                        "
                    >
                        <IconLayoutSidebarLeftCollapse
                            size="26"
                            class="trigger flex-shrink-0 text-gray-600 mt-[12px] transition-all hover:text-sky-400"
                            :class="{
                                'rotate-180': isCollapsed,
                                'ml-6': !isCollapsed,
                            }"
                        />
                    </a>
                </div>

                <left-menu />
                <left-account-settings
                    :user="user"
                    :leftSidebatdCollapsed="isCollapsed"
                />
            </div>
        </a-layout-sider>
    </div>

    <!-- below md: slide-out drawer -->
    <a-drawer
        v-model:visible="mobileDrawerOpen"
        placement="left"
        :width="280"
        :closable="true"
        destroy-on-close
        :body-style="{ padding: 0, display: 'flex', flexDirection: 'column' }"
        wrap-class-name="mobile-nav-drawer-wrap"
        @close="closeMobileDrawer"
    >
        <div class="flex flex-col h-full min-h-0">
            <div class="flex items-center px-6 py-4 border-b border-gray-100">
                <TechikoLogo :height="30" />
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto">
                <left-menu />
            </div>
            <left-account-settings
                :user="user"
                :leftSidebatdCollapsed="false"
            />
        </div>
    </a-drawer>
</template>
