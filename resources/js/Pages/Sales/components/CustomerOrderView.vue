<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
// Removed useOrderV2 import - using direct props instead

// Props for direct data passing
const props = defineProps({
  orderId: { type: [String, Number], default: null }
});
const order = ref(null);

onMounted(() => {
    if (!window.Echo) {
        console.error("Echo is not initialized!");
        return;
    }

    window.Echo.channel("order").listen(".OrderUpdated", (e) => {
        console.log("Order updated:", e.order);
        order.value = e.order;
    });
});

onBeforeUnmount(() => {
    if (window.Echo) {
        window.Echo.leave("order");
    }
});
</script>

<template>
    <div>
        <h2>Your Order</h2>
        <p>Listening to Orders channel...</p>
        <pre>{{ order }}</pre>
    </div>
</template>
