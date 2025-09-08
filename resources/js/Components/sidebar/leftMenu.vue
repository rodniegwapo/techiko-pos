<script setup>
import { ref, computed, watch, onMounted } from "vue";
import {
  IconDashboard,
  IconCategory,
  IconUsers,
  IconBrandProducthunt,
} from "@tabler/icons-vue";
import { router, usePage } from "@inertiajs/vue3";

import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const { selectedKeys, openKeys } = useGlobalVariables();

const page = usePage();
const menus = computed(() => {
  return [
    {
      title: "Dashboard",
      path: route("dashboard"),
      icon: IconDashboard,
      pathName: "dashboard",
    },
    {
      title: "Products",
      icon: IconCategory,
      path: "/products", // important: give parent submenu a path (for key)
      children: [
        {
          title: "Items",
          path: route("products.index"),
        },
        {
          title: "Categories",
          path: route("categories.index"),
        },
        {
          title: "Discounts",
          path: route("products.discounts.index"),
        },
      ],
    },
    {
      title: "Customers",
      path: "/customers",
      icon: IconUsers,
    },
  ];
});

const handleClick = (menu, parentMenu = null) => {
  router.visit(menu.path);

  selectedKeys.value = [menu.path];
  if (parentMenu) {
    openKeys.value = [parentMenu.path];
  }
};

onMounted(() => {
  const url = new URL(window.location.href);
  selectedKeys.value = [url.origin + url.pathname]; // only path, no params
  const parent = menus.value.find((m) =>
    m.children?.some((c) => c.path === selectedKeys.value[0])
  );
  if (parent) {
    openKeys.value = [parent.path];
  }
});
</script>

<template>
  <div class="overflow-auto">
    <a-menu
      v-model:openKeys="openKeys"
      v-model:selectedKeys="selectedKeys"
      mode="inline"
      theme="light"
    >
      <template v-for="menu in menus" :key="menu.path">
        <a-menu-item
          v-if="!menu.children"
          :key="menu.path"
          @click="handleClick(menu)"
          class="font-semibold text-gray-800 items-center"
        >
          <template #icon>
            <span
              class="leading-[40px] h-full flex items-center justify-center"
            >
              <component
                v-if="menu.icon"
                :is="menu.icon"
                class="flex-shrink-0"
              />
            </span>
          </template>
          {{ menu.title }}
        </a-menu-item>

        <a-sub-menu
          v-else
          :key="menu.path"
          class="font-semibold text-gray-800 items-center"
        >
          <template #icon>
            <span
              class="leading-[40px] h-full flex items-center justify-center"
            >
              <component
                v-if="menu.icon"
                :is="menu.icon"
                class="flex-shrink-0"
              />
            </span>
          </template>
          <template #title>{{ menu.title }}</template>

          <a-menu-item
            v-for="child in menu.children"
            :key="child.path"
            @click="handleClick(child, menu)"
          >
            <component
              v-if="child.icon"
              :is="child.icon"
              style="font-size: 20px"
            />
            <span>{{ child.title }}</span>
          </a-menu-item>
        </a-sub-menu>
      </template>
    </a-menu>
  </div>
</template>
