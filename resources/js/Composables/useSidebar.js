// composables/useSidebar.js
import { ref, watch } from 'vue'

// The desktop sidebar's icon-only rail is remembered per browser.
const STORAGE_KEY = 'sidebar_collapsed'

function readCollapsed() {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

const isCollapsed = ref(readCollapsed())
const mobileDrawerOpen = ref(false)

watch(isCollapsed, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, value ? '1' : '0')
  } catch {
    // ignore
  }
})

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
