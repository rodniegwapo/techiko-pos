import { ref } from 'vue'

const cartDrawerOpen = ref(false)

export function useSalesCartDrawer() {
  function openCartDrawer() {
    cartDrawerOpen.value = true
  }

  function closeCartDrawer() {
    cartDrawerOpen.value = false
  }

  function toggleCartDrawer() {
    cartDrawerOpen.value = !cartDrawerOpen.value
  }

  return {
    cartDrawerOpen,
    openCartDrawer,
    closeCartDrawer,
    toggleCartDrawer,
  }
}
