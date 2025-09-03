<script setup>
import { ref } from "vue";
import {
  UserOutlined,
  VideoCameraOutlined,
  UploadOutlined,
} from "@ant-design/icons-vue";
import { IconMenu2 } from "@tabler/icons-vue";
import LeftMenu from "@/Components/sidebar/leftMenu.vue";
import LeftSidebarWrapper from "@/Components/sidebar/leftWrapper.vue";
import LeftAccountSettings from "@/Components/sidebar/leftAccountSettings.vue";
import { useAuth } from "@/composables/useAuth";
import { useSidebar } from "@/composables/useSidebar"



const { user } = useAuth();
const { isCollapsed } = useSidebar();

const selectedKeys = ref(["1"]);
const collapsed = ref(false);
</script>

<template>
  <a-layout>
    <left-sidebar-wrapper >
      <!-- menu -->
      <left-menu />

      <!-- account-settings -->
      <left-account-settings :user="user" :leftSidebatdCollapsed="isCollapsed" />
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


.ant-menu-item.ant-menu-item-selected::after {
  border-right: 4px solid #014945 !important;
}
</style>
