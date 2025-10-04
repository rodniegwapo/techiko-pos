<script setup>
import TechikoLogo from "@/Components/TechikoLogo.vue";
import TLogo from "@/Components/TLogo.vue";
import { IconLayoutSidebarLeftCollapse } from "@tabler/icons-vue";
import { useSidebar } from "@/composables/useSidebar";

const props = defineProps({
    impersonator: Boolean,
});

const { isCollapsed, toggle, setCollapsed } = useSidebar();
</script>

<template>
    <div class="lg:block md:block sm:hidden">
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

                <slot />
            </div>
        </a-layout-sider>
    </div>
</template>
