// composables/useSidebar.js
import { ref } from 'vue'

const isCollapsed = ref(false)
const mobileDrawerOpen = ref(false)

export function useSidebar() {
  function toggle() {
    isCollapsed.value = !isCollapsed.value
  }

  function setCollapsed(value) {
    isCollapsed.value = value
  }

  function openMobileDrawer() {
    mobileDrawerOpen.value = true
  }

  function closeMobileDrawer() {
    mobileDrawerOpen.value = false
  }

  function toggleMobileDrawer() {
    mobileDrawerOpen.value = !mobileDrawerOpen.value
  }

  return {
    isCollapsed,
    mobileDrawerOpen,
    toggle,
    setCollapsed,
    openMobileDrawer,
    closeMobileDrawer,
    toggleMobileDrawer,
  }
}
