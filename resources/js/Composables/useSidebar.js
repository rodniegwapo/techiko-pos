// composables/useSidebar.js
import { ref } from 'vue'

const isCollapsed = ref(false)

export function useSidebar() {
  function toggle() {
    isCollapsed.value = !isCollapsed.value
  }

  function setCollapsed(value) {
    isCollapsed.value = value
  }

  return {
    isCollapsed,
    toggle,
    setCollapsed,
  }
}
