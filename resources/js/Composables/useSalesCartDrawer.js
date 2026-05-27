import { ref } from 'vue'

const cartDrawerOpen = ref(false)
/** Mobile cart drawer: 'order' = lines + Pay footer; 'payment' = TotalAmountSection */
const checkoutStep = ref('order')

export function useSalesCartDrawer() {
  function goToPaymentStep() {
    checkoutStep.value = 'payment'
  }

  function goToOrderStep() {
    checkoutStep.value = 'order'
  }

  function resetCheckoutStep() {
    checkoutStep.value = 'order'
  }

  function openCartDrawer() {
    checkoutStep.value = 'order'
    cartDrawerOpen.value = true
  }

  function closeCartDrawer() {
    cartDrawerOpen.value = false
    checkoutStep.value = 'order'
  }

  function toggleCartDrawer() {
    cartDrawerOpen.value = !cartDrawerOpen.value
    checkoutStep.value = 'order'
  }

  return {
    cartDrawerOpen,
    checkoutStep,
    openCartDrawer,
    closeCartDrawer,
    toggleCartDrawer,
    goToPaymentStep,
    goToOrderStep,
    resetCheckoutStep,
  }
}
