<script setup>
import { computed } from "vue";

const props = defineProps({
  user: { type: Object, default: null },
  collapsed: { type: Boolean, default: false },
  truncate: { type: Function, required: true },
  showEmail: { type: Boolean, default: true },
  size: { type: String, default: "md" },
  collapsible: { type: Boolean, default: false },
  userEmailClass: { type: String, default: "" },
});

const fallbackAvatar = (name) => {
  const q = encodeURIComponent(name || "User");
  return `https://ui-avatars.com/api/?name=${q}&background=287e47&color=ffff`;
};

const avatarSrc = computed(
  () => props.user?.profileUrl ?? fallbackAvatar(props.user?.name),
);
const displayName = computed(() => props.user?.name ?? "User");
const displayEmail = computed(() => props.user?.email ?? "");
</script>

<template>
  <div
    class="flex items-center w-full gap-4"
    :class="{ 'justify-center': collapsed }"
  >
    <!-- Avatar always visible -->
    <img
      class="rounded-full"
      :class="{
        'w-8 h-8': size === 'sm' || collapsed,
        'w-10 h-10': size === 'md' && !collapsed,
      }"
      :src="avatarSrc"
      alt="avatar"
    />

    <!-- Name + Email -->
    <div v-if="!collapsed || !collapsible" :class="userEmailClass">
      <div class="p-0 m-0 text-sm font-bold">
        {{ truncate(displayName, 18) }}
      </div>
      <div v-if="showEmail && displayEmail" class="p-0 m-0 text-xs">
        {{ truncate(displayEmail, 24) }}
      </div>
    </div>
  </div>
</template>
