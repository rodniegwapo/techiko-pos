<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { ref, toRefs } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePage } from "@inertiajs/vue3";
import { useOrders } from "@/Composables/useOrderV2";
import axios from "axios";
import dayjs from "dayjs";

const emit = defineEmits(["close"]);
const { formData, errors } = useGlobalVariables();
const page = usePage();
const { orderId, orders, applyDiscountToLine } = useOrders();

const props = defineProps({
  openModal: Boolean,
  product: { type: Object, default: () => ({}) },
});
const { openModal, product } = toRefs(props);

const loading = ref(false);

/** 🔹 Ensure sale item exists in database before applying discount */
const ensureSaleItemExists = async () => {
  try {
    // Force sync the current draft immediately to ensure sale items exist in database
    await axios.post(`/api/sales/${orderId.value}/sync-immediate`, {
      items: orders.value.map((item) => ({
        id: item.id,
        quantity: item.quantity,
        price: item.price,
        discount_id: item.discount_id || null,
        discount_amount: item.discount_amount || 0,
        subtotal: item.subtotal,
      })),
    });
  } catch (error) {
    console.error("Error syncing draft:", error);
    throw new Error("Failed to sync draft. Please try again.");
  }
};

/** 🔹 Apply discount */
const handleSave = async () => {
  try {
    if (!formData.value?.discount?.value) return emit("close");
    loading.value = true;

    // First, ensure the draft is synced to database
    await ensureSaleItemExists();

    // fetch sale item
    const { data: saleItem } = await axios.get(
      route("sales.find-sale-item", { sale: orderId.value }),
      { params: { product_id: product.value.id } }
    );

    // Check if sale item exists
    if (!saleItem || !saleItem.id) {
      console.error("Sale item not found for product:", product.value.id);
      
      // Show user-friendly notification instead of alert
      const { notification } = await import("ant-design-vue");
      notification.error({
        message: "Sale Item Not Found",
        description: "The product needs to be added to the sale before applying a discount. Please try again.",
        duration: 5,
      });
      return emit("close");
    }

    // selected discount
    const selectedDiscount = page.props.discounts.find(
      (d) => d.id === formData.value.discount.value
    );
    if (!selectedDiscount) return emit("close");

    // backend apply
    await axios.post(
      route("sales.items.discount.apply", {
        sale: orderId.value,
        saleItem: saleItem.id,
      }),
      { discount_id: selectedDiscount.id }
    );

    // update local state
    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      orders.value[idx] = applyDiscountToLine(
        orders.value[idx],
        selectedDiscount
      );
    }

    emit("close");
  } catch (e) {
    console.error("Error applying discount:", e);
    
    // Show user-friendly notification
    const { notification } = await import("ant-design-vue");
    notification.error({
      message: "Discount Application Failed",
      description: e.message || "Failed to apply discount. Please try again.",
      duration: 5,
    });
  } finally {
    loading.value = false;
  }
};

/**  Clear discount */
const discountLoading = ref(false);
const handleClearDiscount = async () => {
  try {
    discountLoading.value = true;

    // fetch sale item
    const { data: saleItem } = await axios.get(
      route("sales.find-sale-item", { sale: orderId.value }),
      { params: { product_id: product.value.id } }
    );

    // Check if sale item exists
    if (!saleItem || !saleItem.id) {
      console.error("Sale item not found for product:", product.value.id);
      alert("Sale item not found. Cannot clear discount.");
      return emit("close");
    }

    // Check if discount_id exists
    if (!product.value.discount_id) {
      console.error("No discount ID found for product:", product.value.id);
      alert("No discount found to clear.");
      return emit("close");
    }

    // backend remove
    await axios.delete(
      route("sales.items.discount.remove", {
        sale: orderId.value,
        saleItem: saleItem.id,
        discount: product.value.discount_id,
      })
    );

    // update local state (reset)
    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      orders.value[idx] = applyDiscountToLine(orders.value[idx], null);
    }

    emit("close");
  } catch (e) {
    console.error("Error clearing discount:", e);
    alert("Failed to clear discount. Please try again.");
  } finally {
    discountLoading.value = false;
  }
};

const formFields = [
  {
    key: "discount",
    label: "Select Discount",
    type: "select",
    isAllowClear: false,
    options: page.props.discounts
      .filter(
        (item) =>
          item.scope == "product" &&
          dayjs(item.start_date).isBefore(dayjs()) &&
          item.is_active
      )
      .map((item) => ({
        label: item.name,
        value: item.id,
        amount: item.value,
      })),
  },
];
</script>
<template>
  <a-modal
    v-model:visible="openModal"
    :title="`Apply Discount - ${product.name}`"
    @cancel="$emit('close')"
    width="400px"
    :maskClosable="false"
  >
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    <template #footer>
      <!-- Clear button only visible if product already has discount -->
      <a-button
        type="danger"
        :loading="discountLoading"
        v-if="product.discount"
        @click="handleClearDiscount"
      >
        Clear Discount
      </a-button>
      <primary-button :loading="loading" @click="handleSave">
        Submit
      </primary-button>
    </template>
  </a-modal>
</template>
