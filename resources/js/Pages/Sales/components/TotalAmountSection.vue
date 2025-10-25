<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import { IconDiscount, IconArrowRightToArc } from "@tabler/icons-vue";
import { useOrders } from "@/Composables/useOrderV2";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { ref, computed, createVNode } from "vue";
import { Modal, notification } from "ant-design-vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";

const { formData, errors } = useGlobalVariables();
const { getRoute } = useDomainRoutes();
const page = usePage();
const {
  orders,
  orderDiscountAmount,
  orderDiscountId,
  totalAmount,
  formattedTotal,
  orderId,
  finalizeOrder,
} = useOrders();

// Get customer data from parent component (will be passed as prop)
const props = defineProps({
  selectedCustomer: {
    type: Object,
    default: null
  }
});

const amountReceived = ref(0);

const openOrderDicountModal = ref(false);

const showDiscountOrder = async () => {
  // Check if there's an active order/draft OR if there are items in the cart
  // (orderId might be null briefly while draft is being created)
  if (!orderId.value && orders.value.length === 0) return;
  
  // Load current discounts from database instead of localStorage
  try {
    const response = await axios.get(getRoute('sales.discounts.current'));
    const { regular_discounts, mandatory_discounts } = response.data;

    // Convert database discounts to option objects for the select components
    const regularDiscountOptions = regular_discounts.map((discount) => ({
      label: `${discount.name} (${
        discount.type === "percentage"
          ? discount.value + "%"
          : "₱" + discount.value
      })`,
      value: discount.id,
      amount: discount.value,
      type: discount.type,
    }));

    // Get the first active mandatory discount
    const mandatoryDiscountOption = mandatory_discounts.length > 0 ? {
      label: `${mandatory_discounts[0].name} (${
        mandatory_discounts[0].type === "percentage"
          ? mandatory_discounts[0].value + "%"
          : "₱" + mandatory_discounts[0].value
      })`,
      value: mandatory_discounts[0].id,
      amount: mandatory_discounts[0].value,
      type: mandatory_discounts[0].type,
    } : null;
  } catch (error) {
    console.error('Failed to load discounts:', error);
    notification.error({
      message: 'Error',
      description: 'Failed to load discount options'
    });
    return;
  }
  
  formData.value = {
    orderDiscount: regularDiscountOptions,
    mandatoryDiscount: mandatoryDiscountOption,
  };
  openOrderDicountModal.value = true;
};

const customerChange = computed(() => {
  const received = Number(amountReceived.value) || 0;
  const total = Number(totalAmount.value) || 0;

  if (received < 1) return 0;
  return received - (total - orderDiscountAmount.value);
});

const proceedPaymentLoading = ref(false);

const handleProceedPaymentConfirmation = () => {
  Modal.confirm({
    title: "Are you sure you would like to proceed?",
    icon: createVNode(ExclamationCircleOutlined),
    okText: "Submit",
    cancelText: "Cancel",
    onOk() {
      return new Promise(async (innerResolve, innerReject) => {
        try {
          await handleProceedPayment();
          innerResolve();
        } catch (error) {
          innerReject(error);
        }
      });
    },
    onCancel() {
      console.log("Cancel");
    },
  });
};

const handleProceedPayment = async () => {
  try {
    proceedPaymentLoading.value = true;
    
    // Single API call to process payment and loyalty together
    const response = await axios.post(
      getRoute("sales.payment.store", {
        sale: orderId.value,
      }),
      {
        // Include customer data for loyalty processing
        customer_id: props.selectedCustomer?.id || null,
        sale_amount: totalAmount.value,
        payment_method: paymentMethod.value
      }
    );
    
    // Clean up and finalize
    amountReceived.value = 0;
    finalizeOrder();
    
    // Show success notification based on response
    const loyaltyResults = response.data.loyalty_results;
    
    if (loyaltyResults && loyaltyResults.points_earned) {
      notification.success({
        message: "Payment Successful!",
        description: `Transaction completed. ${props.selectedCustomer.name} earned ${loyaltyResults.points_earned} points!`,
        duration: 5,
      });
      
      // Show tier upgrade notification if applicable
      if (loyaltyResults.tier_upgraded) {
        setTimeout(() => {
          notification.info({
            message: "Tier Upgraded!",
            description: `🎉 ${props.selectedCustomer.name} is now ${loyaltyResults.new_tier.toUpperCase()} tier!`,
            duration: 8,
          });
        }, 1000);
      }
    } else {
      notification.success({
        message: "Payment Successful!",
        description: "Transaction completed successfully.",
      });
    }
    
    localStorage.setItem("order_discount_amount", 0);
    localStorage.setItem("order_discount_ids", "");
    orderDiscountAmount.value = 0;
    orderDiscountId.value = "";
  } catch (error) {
    notification.error({
      message: "Payment failed",
      description: "Please try again or contact support.",
    });
    throw error;
  } finally {
    proceedPaymentLoading.value = false;
  }
};

const disabledPaymentButtonColor = computed(() => {
  if (amountReceived.value < totalAmount.value) return "";
  if (orders.value.length == 0) return "";
  return "bg-green-700 border-green-700 hover:bg-green-600";
});

const paymentMethod = ref("cash");
</script>

<template>
  <div class="bg-white">
    <div class="px-6 max-w-7xl mx-auto py-4 shadow-sm">
      <!-- Horizontal Layout - Single Row -->
      <div class="flex items-center justify-between gap-6">
        <!-- Order Discount -->
        <div class="flex items-center gap-2">
          <span class="text-gray-700 whitespace-nowrap">Order Discount:</span>
          <span class="font-medium">{{
            formattedTotal(orderDiscountAmount)
          }}</span>
          <icon-tooltip-button
            name="Apply Order Discount"
            :class="{ 'hover:bg-green-700 p-1': orders.length !== 0 }"
            :disabled="orders.length == 0"
            @click="showDiscountOrder"
          >
            <IconDiscount size="20" class="mx-auto" />
          </icon-tooltip-button>
        </div>

        <!-- Subtotal -->
        <div class="flex items-center gap-2">
          <span class="text-gray-700 whitespace-nowrap">Subtotal:</span>
          <span class="font-medium">{{ formattedTotal(totalAmount) }}</span>
        </div>

        <!-- Total -->
        <div class="flex items-center gap-2">
          <span class="text-gray-900 font-semibold whitespace-nowrap"
            >Total:</span
          >
          <span class="font-bold text-green-600 text-lg">
            {{ formattedTotal(totalAmount - (parseFloat(orderDiscountAmount) || 0)) }}
          </span>
        </div>
      </div>

      <hr class="mt-4" />
      <div class="p-2 flex gap-8">
        <!-- Payment Method -->
        <div class="flex items-start flex-col gap-2">
          <span class="text-gray-700 whitespace-nowrap">Payment Method</span>
          <a-radio-group v-model:value="paymentMethod" button-style="solid">
            <a-radio-button value="cash">Pay in Cash</a-radio-button>
            <a-radio-button value="card">Pay in Card</a-radio-button>
          </a-radio-group>
        </div>

        <!-- Amount Received -->
        <div class="flex items-start flex-col gap-2">
          <span class="text-gray-700 whitespace-nowrap">Amount Received:</span>
          <a-input
            v-model:value="amountReceived"
            type="number"
            placeholder="0"
            :class="{
              'border-red-400':
                amountReceived < totalAmount - orderDiscountAmount &&
                orders.length > 0,
            }"
            class="w-34 text-center"
          />
        </div>

        <!-- Change -->
        <div class="flex items-start flex-col gap-2">
          <span class="text-gray-700 whitespace-nowrap">Change:</span>
          <a-input readonly :value="formattedTotal(customerChange)" />
        </div>

        <!-- Proceed Payment Button -->
        <div class="flex flex-col gap-2">
          <div class="invisible">Proceed Payment</div>
          <a-button
            type="primary"
            class="w-[300px]"
            :class="disabledPaymentButtonColor"
            @click="handleProceedPaymentConfirmation"
            :disabled="
              proceedPaymentLoading ||
              amountReceived < totalAmount - orderDiscountAmount ||
              orders.length == 0
            "
            :loading="proceedPaymentLoading"
          >
            Proceed Payment
          </a-button>
        </div>
      </div>

      <!-- Order Discount Modal -->
      <apply-order-discount-modal
        :openModal="openOrderDicountModal"
        @close="openOrderDicountModal = false"
      />
    </div>
  </div>
</template>
