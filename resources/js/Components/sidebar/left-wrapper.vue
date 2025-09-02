<script setup>
import TechikoLogo from '@/Components/TechikoLogo.vue'
import TLogo from '@/Components/TLogo.vue'
import { IconLayoutSidebarLeftCollapse } from '@tabler/icons-vue'
import { useSidebar } from '@/composables/useSidebar'


const emit = defineEmits(['setCollapsed'])
const props = defineProps({
  impersonator: Boolean
})

const { isCollapsed, toggle, setCollapsed } = useSidebar()
</script>

<template>
  <div class="lg:block md:block sm:hidden">
    <a-layout-sider
      :width="264"
      v-model:collapsed="isCollapsed"
      :trigger="null"
      theme="light"
      collapsible
      class="sticky top-0 z-50 h-screen"
    >
      <div class="flex relative w-full flex-col h-full">
        <div
          class="px-5 pt-4 mb-4 text-white flex items-center justify-center"
          :class="isCollapsed ? 'flex-col-reverse items-center' : 'space-x-2'"
          :style="impersonator ? 'margin-top:45px' : ''"
        >
          <TechikoLogo v-if="!isCollapsed" :height="30" />
          <TLogo v-else style="margin-top: 28px !important" />

          <a role="button" @click="() => { toggle(); emit('setCollapsed') }">
            <IconLayoutSidebarLeftCollapse
              size="26"
              class="trigger flex-shrink-0 text-gray-600 transition-all hover:text-sky-400"
              :class="{ 'rotate-180': isCollapsed }"
            />
          </a>
        </div>

        <slot />
      </div>
    </a-layout-sider>
  </div>
</template>
