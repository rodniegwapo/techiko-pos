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

// Removed ensureSaleItemExists - no longer needed with database-driven approach

/** 🔹 Apply discount */
const handleSave = async () => {
  try {
    if (!formData.value?.discount?.value) return emit("close");
    loading.value = true;

    // fetch sale item (data is already in database via user-specific routes)
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
        description:
          "The product needs to be added to the sale before applying a discount. Please try again.",
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
    const { data: response } = await axios.post(
      route("sales.items.discount.apply", {
        sale: orderId.value,
        saleItem: saleItem.id,
      }),
      { discount_id: selectedDiscount.id }
    );

    // update local state with backend response data
    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      // Update with actual backend calculated values
      const updatedItem = {
        ...orders.value[idx],
        discount_id: selectedDiscount.id,
        discount_type: selectedDiscount.type,
        discount: selectedDiscount.value,
        discount_amount: response.item.discount,
        subtotal: response.item.subtotal,
      };

      console.log("Updating item with discount:", updatedItem);
      orders.value[idx] = updatedItem;
    }

    // Show success notification
    const { notification } = await import("ant-design-vue");
    notification.success({
      message: "Discount Applied",
      description: `${selectedDiscount.name} has been applied to ${product.value.name}`,
      duration: 3,
    });

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

    console.log("slea item", saleItem);
    // Check if sale item exists
    if (!saleItem || !saleItem.id) {
      console.error("Sale item not found for product:", product.value.id);
      alert("Sale item not found. Cannot clear discount.");
      return emit("close");
    }

    // Check if there are any discounts to clear
    // Check both discount amount and discounts relationship
    const hasDiscountAmount = saleItem.discount && parseFloat(saleItem.discount) > 0;
    const hasDiscountRelationship = saleItem.discounts && saleItem.discounts.length > 0;
    
    if (!hasDiscountAmount && !hasDiscountRelationship) {
      console.log("No discounts found to clear for product:", product.value.id);
      console.log("saleItem.discount:", saleItem.discount);
      console.log("saleItem.discounts:", saleItem.discounts);
      
      // Show user-friendly notification
      const { notification } = await import("ant-design-vue");
      notification.info({
        message: "No Discount Found",
        description: "This product doesn't have any discounts to clear.",
        duration: 3,
      });
      return emit("close");
    }

    // Get the discount to remove
    let discountToRemove = null;
    
    if (hasDiscountRelationship) {
      // Use the first discount from the relationship
      discountToRemove = saleItem.discounts[0];
    } else {
      // If no relationship but has discount amount, we need to find the discount
      // This might happen if the relationship wasn't loaded properly
      console.error("Item has discount amount but no discount relationship loaded");
      
      const { notification } = await import("ant-design-vue");
      notification.error({
        message: "Clear Discount Failed",
        description: "Unable to identify the discount to remove. Please refresh and try again.",
        duration: 5,
      });
      return emit("close");
    }

    // backend remove
    const { data: response } = await axios.delete(
      route("sales.items.discount.remove", {
        sale: orderId.value,
        saleItem: saleItem.id,
        discount: discountToRemove.id,
      })
    );

    // update local state with backend response data (reset)
    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      orders.value[idx] = {
        ...orders.value[idx],
        discount_id: null,
        discount_type: null,
        discount: 0,
        discount_amount: 0,
        subtotal: response.item.subtotal,
      };
    }

    // Show success notification
    const { notification } = await import("ant-design-vue");
    notification.success({
      message: "Discount Cleared",
      description: "Product discount has been successfully removed.",
      duration: 3,
    });

    emit("close");
  } catch (e) {
    console.error("Error clearing discount:", e);

    // Show user-friendly notification instead of alert
    const { notification } = await import("ant-design-vue");
    notification.error({
      message: "Clear Discount Failed",
      description:
        e.response?.data?.message ||
        "Failed to clear discount. Please try again.",
      duration: 5,
    });
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
        v-if="
          product.discount_id ||
          (product.discount_amount && product.discount_amount > 0)
        "
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
