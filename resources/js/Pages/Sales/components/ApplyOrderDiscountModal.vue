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

    // Ensure we have an orderId (create draft if needed)
    if (!orderId.value) {
      const { createDraft } = useOrders();
      await createDraft();
    }

    // Separate regular and mandatory discount IDs
    const selectedRegularIds = formData.value?.orderDiscount ? 
      formData.value.orderDiscount
        .map((item) => item.value || item.id || item)
        .filter(id => id !== null && id !== undefined && id !== '' && !isNaN(id)) : [];
    
    const selectedMandatoryIds = formData.value?.mandatoryDiscount ? 
      [formData.value.mandatoryDiscount.value || formData.value.mandatoryDiscount.id || formData.value.mandatoryDiscount]
        .filter(id => id !== null && id !== undefined && id !== '' && !isNaN(id)) : [];

    // Debug logging
    console.log('Form data:', formData.value);
    console.log('Selected regular IDs:', selectedRegularIds);
    console.log('Selected mandatory IDs:', selectedMandatoryIds);

    const payload = {
      discount_ids: selectedRegularIds,
      mandatory_discount_ids: selectedMandatoryIds,
    };
    loading.value = true;
    const { data: sale } = await axios.post(
      route("sales.discounts.order.apply", {
        sale: orderId.value,
      }),
      payload
    );

    const { sale_discounts } = sale;
    
    // Separate regular and mandatory discount IDs
    const regularDiscounts = sale_discounts.filter(item => item.discount_type === 'regular');
    const mandatoryDiscounts = sale_discounts.filter(item => item.discount_type === 'mandatory');
    
    const regularDiscountIds = regularDiscounts.map(item => item.discount_id).join(",");
    const mandatoryDiscountIds = mandatoryDiscounts.map(item => item.discount_id).join(",");
    
    // Store separately for proper retrieval
    localStorage.setItem("order_discount_amount", sale?.sale?.discount_amount ?? 0);
    localStorage.setItem("regular_discount_ids", regularDiscountIds);
    localStorage.setItem("mandatory_discount_ids", mandatoryDiscountIds);
    
    // Keep the old format for backward compatibility
    const allDiscountIds = sale_discounts.map(item => item.discount_id).join(",");
    localStorage.setItem("order_discount_ids", allDiscountIds);

    orderDiscountAmount.value = sale?.sale?.discount_amount ?? 0;
    orderDiscountId.value = allDiscountIds;
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
  
  // More robust checking for regular discounts
  const hasRegularDiscount = dataForm.orderDiscount && 
    Array.isArray(dataForm.orderDiscount) &&
    dataForm.orderDiscount.filter((item) => 
      item && (item.value || item.id) && 
      item.value !== null && item.value !== undefined && item.value !== ''
    ).length > 0;
  
  // More robust checking for mandatory discounts
  const hasMandatoryDiscount = dataForm.mandatoryDiscount && 
    (dataForm.mandatoryDiscount.value || dataForm.mandatoryDiscount.id || typeof dataForm.mandatoryDiscount === 'number') &&
    dataForm.mandatoryDiscount.value !== null && dataForm.mandatoryDiscount.value !== undefined && dataForm.mandatoryDiscount.value !== '';

  if (!hasRegularDiscount && !hasMandatoryDiscount) {
    errors.value = {
      orderDiscount: ["Please select at least one discount (promotional or mandatory)"],
      mandatoryDiscount: ["Please select at least one discount (promotional or mandatory)"]
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
    localStorage.removeItem("regular_discount_ids");
    localStorage.removeItem("mandatory_discount_ids");
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

const availableMandatoryDiscounts = computed(() => {
  return (page.props.mandatoryDiscounts || [])
    .map((item) => ({
      label: `${item.name} (${item.type === 'percentage' ? item.value + '%' : '₱' + item.value})`,
      value: item.id,
      amount: item.value,
      type: item.type,
      isMandatory: true,
    }));
});

// Helper function to get the best mandatory discount
const getBestMandatoryDiscount = computed(() => {
  if (availableMandatoryDiscounts.value.length === 0) return null;
  
  // For now, return the one with highest percentage/amount
  return availableMandatoryDiscounts.value.reduce((best, current) => {
    if (current.type === 'percentage' && best.type === 'percentage') {
      return current.amount > best.amount ? current : best;
    } else if (current.type === 'amount' && best.type === 'amount') {
      return current.amount > best.amount ? current : best;
    } else if (current.type === 'percentage' && best.type === 'amount') {
      // Assume percentage is usually better for larger orders
      return current;
    }
    return best;
  });
});

// Check if multiple mandatory discounts are available (PWD + Senior scenario)
const hasMultipleMandatoryOptions = computed(() => {
  return availableMandatoryDiscounts.value.length > 1;
});

const formFields = computed(() => [
  {
    key: "orderDiscount",
    label: "Promotional Discounts",
    type: "select",
    isAllowClear: false,
    multiple: true,
    options: availableDiscounts.value,
    placeholder: availableDiscounts.value.length > 0 
      ? "Choose promotional discount(s)" 
      : "No promotional discounts available",
  },
  {
    key: "mandatoryDiscount",
    label: "Mandatory Discounts (Choose ONE Only)",
    type: "select",
    isAllowClear: false,
    multiple: false, // Regulatory requirement: only one mandatory discount
    options: availableMandatoryDiscounts.value,
    placeholder: availableMandatoryDiscounts.value.length > 0 
      ? "Select PWD, Senior, Student, etc." 
      : "No mandatory discounts available",
    helpText: hasMultipleMandatoryOptions.value 
      ? "⚠️ Customer with multiple eligibilities (PWD + Senior) can only use ONE discount"
      : null,
  },
]);
</script>
<template>
  <a-modal
    v-model:visible="openModal"
    :title="`Apply Order Discount`"
    @cancel="$emit('close')"
    width="500px"
    :maskClosable="false"
  >
    <div v-if="availableDiscounts.length === 0 && availableMandatoryDiscounts.length === 0" class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
      <p class="text-yellow-800 text-sm">
        <strong>No discounts available:</strong> There are currently no active discounts that can be applied.
      </p>
    </div>
    
    <!-- Enhanced Info Section -->
    <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded">
      <details class="text-sm text-blue-800">
        <summary class="cursor-pointer font-medium text-blue-700">📋 Discount Types</summary>
        <div class="mt-2 space-y-1">
          <p><strong>Promotional:</strong> Regular sales discounts (can select multiple)</p>
          <p><strong>Mandatory:</strong> PWD, Senior Citizen, Student discounts</p>
          <p class="text-xs italic text-blue-600">⚠️ Regulatory Requirement: Only ONE mandatory discount per transaction</p>
        </div>
      </details>
    </div>
    
    <!-- PWD + Senior Guidance -->
    <div v-if="hasMultipleMandatoryOptions" class="mb-4 p-3 bg-orange-50 border border-orange-200 rounded">
      <details class="text-sm text-orange-800">
        <summary class="cursor-pointer font-medium text-orange-700">⚠️ Multiple Mandatory Discounts Available</summary>
        <div class="mt-2 space-y-1">
          <p class="text-orange-700">If customer qualifies for multiple discounts (PWD + Senior), they must choose ONE.</p>
          <p class="text-orange-700">Help them select the most beneficial option or their preferred discount.</p>
          <div v-if="getBestMandatoryDiscount" class="text-orange-600">
            <strong>Suggestion:</strong> {{ getBestMandatoryDiscount.label }} might be the best value
          </div>
        </div>
      </details>
    </div>

    <!-- Business Rules Reference -->
    <div class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded">
      <details class="text-xs text-gray-600">
        <summary class="cursor-pointer font-medium text-gray-700">📋 Cashier Guidelines</summary>
        <div class="mt-2 space-y-1">
          <p><strong>ID Verification:</strong> Always verify customer ID for mandatory discounts</p>
          <p><strong>Multiple Eligibility:</strong> Customer chooses ONE mandatory discount only</p>
          <p><strong>Combining:</strong> Promotional + Mandatory discounts can be combined</p>
          <p><strong>Documentation:</strong> Ensure proper receipt notation for audit compliance</p>
        </div>
      </details>
    </div>
    
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    
    <template #footer>
      <!-- Clear button -->
      <a-button
        type="danger"
        :loading="discountLoading"
        @click="handleClearDiscount"
      >
        Clear All Discounts
      </a-button>
      
      <primary-button 
        :loading="loading" 
        :disabled="availableDiscounts.length === 0 && availableMandatoryDiscounts.length === 0"
        @click="handleSave"
      >
        Apply Discount(s)
      </primary-button>
    </template>
  </a-modal>
</template>