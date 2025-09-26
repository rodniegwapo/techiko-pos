<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import { IconDiscount, IconArrowRightToArc } from "@tabler/icons-vue";
import { useOrders } from "@/Composables/useOrderV2";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { ref, computed, createVNode } from "vue";
import { Modal, notification } from "ant-design-vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import axios from "axios";

const { formData, errors } = useGlobalVariables();
const {
  orders,
  orderDiscountAmount,
  orderDiscountId,
  totalAmount,
  formattedTotal,
  orderId,
  finalizeOrder,
} = useOrders();

const amountReceived = ref(0);

const openOrderDicountModal = ref(false);

const showDiscountOrder = () => {
  if (orders.value.length == 0) return;
  formData.value = {
    orderDiscount: orderDiscountId.value
      ? orderDiscountId.value.split(",").map((item) => Number(item))
      : [],
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
    await axios.post(
      route("sales.payment.store", {
        sale: orderId.value,
      })
    );
    amountReceived.value = 0;
    finalizeOrder();
    notification["success"]({
      message: "Success",
    });
    localStorage.setItem("order_discount_amount", 0);
    localStorage.setItem("order_discount_ids", "");
    orderDiscountAmount.value = 0;
    orderDiscountId.value = "";
  } catch (error) {
    notification["error"]({
      message: "Payment failed",
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
            {{ formattedTotal(totalAmount - Number(orderDiscountAmount)) }}
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
          <a-input disabled :value="formattedTotal(customerChange)" />
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
