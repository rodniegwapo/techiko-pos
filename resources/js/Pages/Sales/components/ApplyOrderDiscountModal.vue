<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { computed, onMounted, ref, toRefs, watch } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePage } from "@inertiajs/vue3";
import { useOrders } from "@/Composables/useOrderV2";
import axios from "axios";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";

const emit = defineEmits(["close"]);
const { formData, errors } = useGlobalVariables();
const page = usePage();
const {
  orderId,
  orders,
  orderDiscountAmount,
  orderDiscountId,
  applyDiscountToLine,
} = useOrders();

const props = defineProps({
  openModal: Boolean,
  product: { type: Object, default: () => ({}) },
});
const { openModal, product } = toRefs(props);

// Watch for modal opening to clear previous errors
watch(openModal, (newValue) => {
  if (newValue) {
    errors.value = {};
  }
});

const loading = ref(false);

/** 🔹 Apply discount */

const handleSave = async () => {
  try {
    if (!checkedForm(formData.value)) return emit("close");

    const payload = {
      discount_ids: formData.value?.orderDiscount.map((item) => item.value),
    };
    loading.value = true;
    const { data: sale } = await axios.post(
      route("sales.discounts.order.apply", {
        sale: orderId.value,
      }),
      payload
    );

    const { sale_discounts } = sale;
    const joinDiscountsIds = sale_discounts
      .map((item) => item.discount_id)
      .join(",");

    localStorage.setItem(
      "order_discount_amount",
      sale?.sale?.discount_amount ?? 0
    );
    localStorage.setItem(
      "order_discount_ids",
      sale_discounts ? joinDiscountsIds : ""
    );

    orderDiscountAmount.value = sale?.sale?.discount_amount ?? 0;
    orderDiscountId.value = joinDiscountsIds;
    formData.value = {};

    // Clear any previous errors on success
    errors.value = {};
    
    notification["success"]({
      message: "Success",
      description: "Discount(s) applied successfully",
    });
    
    emit("close");
  } catch (error) {
    console.error("Error applying discount:", error);
    
    // Handle validation errors from backend
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors || {};
    } else if (error.response?.status === 400) {
      // Handle InvalidArgumentException from Sale model
      const errorMessage = error.response.data.message || "Invalid discount application";
      
      notification["error"]({
        message: "Discount Error",
        description: errorMessage,
        duration: 5,
      });
      
      // Set form-level error
      errors.value = {
        orderDiscount: [errorMessage]
      };
    } else {
      // Handle other errors
      notification["error"]({
        message: "Error",
        description: "Failed to apply discount. Please try again.",
      });
    }
  } finally {
    loading.value = false;
  }
};

const checkedForm = (dataForm) => {
  // Clear previous errors
  errors.value = {};
  
  if (!dataForm.orderDiscount) {
    errors.value = {
      orderDiscount: ["Please select at least one discount"]
    };
    return false;
  }
  
  const values = dataForm.orderDiscount.filter((item) =>
    item.hasOwnProperty("value")
  );

  if (values.length == 0) {
    errors.value = {
      orderDiscount: ["Please select at least one discount"]
    };
    return false;
  }

  return true;
};

/**  Clear discount */
const discountLoading = ref(false);
const handleClearDiscount = async () => {
  try {
    discountLoading.value = true;

    // backend remove
    await axios.delete(
      route("sales.discounts.order.remove", {
        sale: orderId.value,
      })
    );

    localStorage.removeItem("order_discount_amount");
    localStorage.removeItem("order_discount_ids");
    orderDiscountAmount.value = 0;
    orderDiscountId.value = '';

    // Clear any previous errors on success
    errors.value = {};
    
    notification["success"]({
      message: "Success",
      description: "Discount(s) cleared successfully",
    });

    emit("close");
  } catch (error) {
    console.error("Error clearing discount:", error);
    
    notification["error"]({
      message: "Error",
      description: error.response?.data?.message || "Failed to clear discount. Please try again.",
    });
  } finally {
    discountLoading.value = false;
  }
};

const availableDiscounts = computed(() => {
  return page.props.discounts
    .filter(
      (item) =>
        item.scope == "order" &&
        item.is_active &&
        (!item.start_date || dayjs(item.start_date).isBefore(dayjs())) &&
        (!item.end_date || dayjs(item.end_date).isAfter(dayjs()))
    )
    .map((item) => ({
      label: `${item.name} (${item.type === 'percentage' ? item.value + '%' : '₱' + item.value})`,
      value: item.id,
      amount: item.value,
      type: item.type,
      min_order_amount: item.min_order_amount,
    }));
});

const formFields = computed(() => [
  {
    key: "orderDiscount",
    label: "Select Discount",
    type: "select",
    isAllowClear: false,
    multiple: true,
    options: availableDiscounts.value,
    placeholder: availableDiscounts.value.length > 0 
      ? "Choose discount(s) to apply" 
      : "No active discounts available",
  },
]);
</script>
<template>
  <a-modal
    v-model:visible="openModal"
    :title="`Apply Order Discount`"
    @cancel="$emit('close')"
    width="400px"
    :maskClosable="false"
  >
    <div v-if="availableDiscounts.length === 0" class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
      <p class="text-yellow-800 text-sm">
        <strong>No discounts available:</strong> There are currently no active order-level discounts that can be applied.
      </p>
    </div>
    
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    <template #footer>
      <!-- Clear button only visible if product already has discount -->
      <a-button
        type="danger"
        :loading="discountLoading"
        @click="handleClearDiscount"
      >
        Clear Discount
      </a-button>
      <primary-button 
        :loading="loading" 
        :disabled="availableDiscounts.length === 0"
        @click="handleSave"
      >
        Submit
      </primary-button>
    </template>
  </a-modal>
</template>
