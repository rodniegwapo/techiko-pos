<script setup>
const props = defineProps({
  user: { type: Object, required: true },
  collapsed: { type: Boolean, default: false },
  truncate: { type: Function, required: true },
  showEmail: { type: Boolean, default: true },
  size: { type: String, default: "md" },
  collapsible: { type: Boolean, default: false },
  userEmailClass: { type: String, default: '' },
  
});
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
      :src="user.profileUrl"
      alt="avatar"
    />

    <!-- Name + Email -->
    <div v-if="!collapsed || !collapsible" :class="userEmailClass">
      <div class="p-0 m-0 text-sm font-bold">
        {{ truncate(user.name, 18) }}
      </div>
      <div v-if="showEmail" class="p-0 m-0 text-xs">
        {{ truncate(user.email, 24) }}
      </div>
      
    
    </div>
  </div>
</template>
