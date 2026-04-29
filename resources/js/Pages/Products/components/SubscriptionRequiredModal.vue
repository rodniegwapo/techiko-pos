<script setup>
import { ref } from "vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { message } from "ant-design-vue";

const props = defineProps({
  visible: { type: Boolean, default: false },
  productCount: { type: Number, default: 0 },
  productLimit: { type: Number, default: 10 },
});

const emit = defineEmits(["update:visible", "subscribed"]);

const { getRoute } = useDomainRoutes();
const subscribing = ref(false);

const close = () => {
  emit("update:visible", false);
};

const startSubscribe = async () => {
  const url = getRoute("billing.paymongo.subscribe");
  if (!url || url === "#") {
    message.error("Could not resolve subscribe route.");
    return;
  }
  subscribing.value = true;
  try {
    const { data } = await window.axios.post(url);
    if (data.subscription_active) {
      message.success("Subscription is active.");
      emit("subscribed");
      close();
      return;
    }
    const redirect =
      data.payment?.next_action_redirect_url ||
      data.payment?.next_action_redirect;
    if (redirect) {
      window.location.href = redirect;
      return;
    }
    if (data.payment?.client_key) {
      message.info(
        "Complete payment in PayMongo using the returned client key (integrate PayMongo.js if needed)."
      );
    } else {
      message.success(
        data.message ||
          "Subscription started. Complete any required payment steps in PayMongo."
      );
    }
    emit("subscribed");
  } catch (e) {
    const msg =
      e.response?.data?.message ||
      e.response?.data?.errors?.[0]?.detail ||
      "Could not start subscription.";
    message.error(msg);
  } finally {
    subscribing.value = false;
  }
};
</script>

<template>
  <a-modal
    :visible="visible"
    title="Subscription required"
    :footer="null"
    @cancel="close"
  >
    <p class="text-gray-700 mb-4">
      Your organization has
      <strong>{{ productCount }}</strong>
      products. The free plan allows up to
      <strong>{{ productLimit }}</strong>
      products. Subscribe to add more.
    </p>
    <div class="flex justify-end gap-2">
      <a-button @click="close">Close</a-button>
      <a-button
        type="primary"
        :loading="subscribing"
        @click="startSubscribe"
      >
        Subscribe
      </a-button>
    </div>
  </a-modal>
</template>
