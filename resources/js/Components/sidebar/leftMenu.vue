<script setup>
import { ref, computed, watch, onMounted } from "vue";
import {
  IconDashboard,
  IconCategory,
  IconUsers,
  IconBrandProducthunt,
  IconHeartHandshake,
  IconHistory,
  IconGift,
  IconUserCog,
} from "@tabler/icons-vue";
import { router, usePage } from "@inertiajs/vue3";

import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const { selectedKeys, openKeys } = useGlobalVariables();

const page = usePage();

// Check if user has admin or super admin role (managers and supervisors can also manage limited users)
const canManageUsers = computed(() => {
  const user = page.props.auth?.user?.data;
  if (!user || !user.roles) return false;
  return user.roles.some(role => {
    const roleName = role.name.toLowerCase();
    return ['super admin', 'admin', 'manager', 'supervisor'].includes(roleName);
  });
});

const menus = computed(() => {
  const baseMenus = [
    {
      title: "Dashboard",
      path: route("dashboard"),
      icon: IconDashboard,
      pathName: "dashboard",
    },
    {
      title: "Sales",
      path: route("sales.index"),
      icon: IconHeartHandshake,
      pathName: "sales",
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
        {
          title: "Mandatory Discounts",
          path: route("mandatory-discounts.index"),
        },
      ],
    },
    {
      title: "Loyalty Program",
      path: route("loyalty.index"),
      icon: IconGift,
      pathName: "loyalty",
    },
    {
      title: "Void logs",
      path: route("voids.index"),
      icon: IconHistory,
    },
    {
      title: "Customers",
      path: "/customers",
      icon: IconUsers,
    },
  ];

  // Add User Management menu item only for Super Admin and Admin
  if (canManageUsers.value) {
   
    baseMenus.push({
      title: "Users",
      path: route("users.index"),
      icon: IconUserCog,
      pathName: "users",
    });
  }

  return baseMenus;
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
          :key="`item-${menu.path}`"
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
          :key="`submenu-${menu.path}`"
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
