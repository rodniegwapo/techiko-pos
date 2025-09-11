<script setup>
import LeftMenu from "@/Components/sidebar/leftMenu.vue";
import LeftSidebarWrapper from "@/Components/sidebar/leftWrapper.vue";
import LeftAccountSettings from "@/Components/sidebar/leftAccountSettings.vue";
import Terminal from "@/Components/Terminal.vue";

import { onMounted, ref } from "vue";
import {
  UserOutlined,
  VideoCameraOutlined,
  UploadOutlined,
} from "@ant-design/icons-vue";
import { IconMenu2 } from "@tabler/icons-vue";
import { useAuth } from "@/composables/useAuth";
import { useSidebar } from "@/composables/useSidebar";

const { user } = useAuth();
const { isCollapsed } = useSidebar();

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
  <a-layout
    class="relative bg-dots-darker bg-center bg-gray-100 dark:bg-dots-lighter dark:bg-gray-900 selection:bg-red-500 selection:text-white"
  >
    <terminal />
    <left-sidebar-wrapper>
      <!-- menu -->
      <left-menu />

      <!-- account-settings -->
      <left-account-settings
        :user="user"
        :leftSidebatdCollapsed="isCollapsed"
      />
    </left-sidebar-wrapper>

    <a-layout-content
      class="max-w-7xl mx-auto p-6 lg:overflow-auto md:overflow-auto sm:overflow-scroll"
    >
      <slot />
    </a-layout-content>
  </a-layout>
</template>

<style>
.ant-menu-item-selected {
  @apply bg-green-500/20 text-green-500 !important;
}

.ant-menu-item.ant-menu-item-selected::after {
  border-right: 4px solid #014945 !important;
}
</style>-200
