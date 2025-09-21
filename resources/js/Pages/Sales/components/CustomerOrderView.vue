<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { useOrders } from "@/Composables/useOrderV2";

const { orderId } = useOrders();
const order = ref(null);

onMounted(() => {
  if (!window.Echo) {
    console.error("Echo is not initialized!");
    return;
  }

  window.Echo.channel('order')
    .listen('.OrderUpdated', (e) => {
      console.log("Order updated:", e.order);
      order.value = e.order;
    });
});

onBeforeUnmount(() => {
  if (window.Echo) {
    window.Echo.leave('order');
  }
});
</script>

<template>
  <div>
    <h2>Order Updates</h2>
    <p>Listening to Orders channel...</p>
    <pre>{{ order }}</pre>
  </div>
</template>
