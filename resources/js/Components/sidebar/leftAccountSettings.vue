<script setup>
import { ref } from "vue";
import { LogoutOutlined, SettingOutlined } from "@ant-design/icons-vue";
import { Link } from "@inertiajs/vue3";
import UserProfileMini from "./UserProfileMini.vue";

const props = defineProps({
  user: { type: Object, default: {} },
  leftSidebatdCollapsed: Boolean,
  showSettings: { type: Boolean, default: true },
});

let selectedKeys = ref(null);

const truncate = (str, length = 100, ending = "...") => {
  return str.length > length
    ? str.substring(0, length - ending.length) + ending
    : str;
};
</script>

<template>
  <div class="left-0 w-full border-t sticky top-[100vh] ">
    <div
      class="py-4 px-6 lg-block xl-block"
      :class="{ 'px-0': leftSidebatdCollapsed }"
    >
      <!-- Popover Settings -->
    <div class="relative ">
       <a-popover
        v-if="showSettings"
        :trigger="['click']"
        placement="rightBottom"
        overlayClassName="account-settings-pop-custom-css"
     
      >
        <template #content>
          <div class="flex flex-col gap-4 py-2">
            <Link class="flex items-center text-gray-800 hover:text-green-500">
              <SettingOutlined class="mr-4" /> Account Settings
            </Link>
            <Link
              class="flex items-center text-gray-800 hover:text-green-500"
              :href="route('logout')"
              method="post"
              as="button"
              type="button"
            >
              <LogoutOutlined class="mr-4" /> Logout
            </Link>
          </div>
        </template>

        <template #title>
          <UserProfileMini
            :user="user"
            :collapsed="leftSidebatdCollapsed"
            :truncate="truncate"
            :collapsible="false"
            userEmailClass="py-2 min-w-[150px]"
          />
        </template>

        <div class="flex items-center cursor-pointer w-full" @click.prevent>
          <UserProfileMini
            :user="user"
            :collapsed="leftSidebatdCollapsed"
            :truncate="truncate"
            :collapsible="true"
          />
        </div>
      </a-popover>
    </div> 
    </div>
  </div>
</template>

<style>
.account-settings-pop-custom-css .ant-popover-content {
  border-radius: 10px !important;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  overflow: hidden;
}


</style>