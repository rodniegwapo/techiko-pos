<script setup>
import { ref } from "vue";
import {
    UserOutlined,
    VideoCameraOutlined,
    UploadOutlined,
} from "@ant-design/icons-vue";
import { IconMenu2 } from "@tabler/icons-vue";
import LeftMenu from "@/Components/sidebar/left-menu.vue";
import LeftSidebarWrapper from "@/Components/sidebar/left-wrapper.vue";

const selectedKeys = ref(["1"]);
const collapsed = ref(false);
</script>

<template>
    <a-layout>
      <left-sidebar-wrapper
            
                @setCollapsed="setCollapsed"
            >
            <left-menu />
            </left-sidebar-wrapper>
        <a-layout>
            <main>
                <slot />
            </main>
        </a-layout>
    </a-layout>
</template>

<style>


.ant-menu-item-selected {
    @apply bg-green-500/20 text-green-500 !important;
}

.ant-menu-item {
    height: 40px !important;
    line-height: 40px;
    padding-inline: 16px;
    overflow: hidden;
    text-overflow: ellipsis !important;
    margin-inline: 0 !important;
    margin-block: 4px !important;
    width: 100% !important;
    border-radius: 0 !important;
    font-size: 16px !important;
    @apply text-gray-700 !important;
    @apply font-semibold !important;
}

.ant-menu-item.ant-menu-item-selected {
    border-right: 4px solid #014945 !important;
}
</style>
