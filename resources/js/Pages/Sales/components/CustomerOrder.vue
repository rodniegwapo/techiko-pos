<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import ApplyDiscountModal from "./ApplyDiscountModal.vue";
import { ref, inject, computed } from "vue";
import {
  CloseOutlined,
  PlusSquareOutlined,
  MinusSquareOutlined,
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useOrders } from "@/Composables/useOrderV2";
import { useHelpers } from "@/Composables/useHelpers";
import axios from "axios";
import { notification } from "ant-design-vue";

const { formData, errors } = useGlobalVariables();
const {
  orders,
  handleAddOrder,
  handleSubtractOrder,
  totalAmount,
  formattedTotal,
  removeOrder,
  orderId,
  finalizeOrder,
} = useOrders();

const { formattedPercent } = useHelpers();

const formFields = [
  { key: "amount", label: "Amount", type: "text", disabled: true },
  { key: "sale_item", label: "Item", type: "text", disabled: true },
  { key: "pin_code", label: "Enter Pin", type: "password" },
  {
    key: "reason",
    label: "Reason",
    type: "textarea",
  },
];

const openvoidModal = ref(false);

const showVoidItem = async (product) => {
  errors.value = {};
  openvoidModal.value = true;
  formData.value = {
    ...product,
    sale_item: product.name,
    amount: product.price,
    product_id: product.id,
  };
};

const loading = ref(false);
const handleSubmitVoid = async () => {
  try {
    loading.value = true;
    await axios.post(
      route("sales.items.void", {
        sale: orderId.value,
      }),
      formData.value
    );
    removeOrder(formData.value);
    openvoidModal.value = false;
    clearForm();
    notification["success"]({
      message: "Success",
      description: "The item was successfully voided.",
    });
  } catch ({ response }) {
    errors.value = response?.data?.errors;
  } finally {
    loading.value = false;
  }
};

let amountReceived = ref(0);

const customerChange = computed(() => {
  const received = Number(amountReceived.value) || 0;
  const total = Number(totalAmount.value) || 0;

  if (received < 1) return 0;
  return received - total;
});

const proceedPaymentLoading = ref(false);
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
  } catch (error) {
  } finally {
    proceedPaymentLoading.value = false;
  }
};

const disabledPaymentButtonColor = computed(() => {
  if (amountReceived.value < totalAmount.value) return "";
  return "bg-green-700 border-green-700 hover:bg-green-600";
});

const clearForm = () => {
  formData.value = {
    amount: "",
    sale_item: "",
    pin_code: "",
    reason: "",
    product_id: null,
  };
};

const currentProduct = ref({});
const openApplyDiscountModal = ref(false);
const handleShowDiscountModal = (order) => {
  formData.value = {
    discount: order?.discount_id,
  };
  currentProduct.value = order;
  openApplyDiscountModal.value = true;
};
</script>

<template>
  <div class="space-y-2">
    <div class="font-semibold text-lg">Current Order</div>
    <a-input value="Walk-in Customer" disabled />
  </div>
  <div
    class="relative flex flex-col gap-2 mt-2 h-[calc(100vh-400px)] overflow-auto overflow-x-hidden"
  >
    <div
      v-if="orders.length == 0"
      class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
    >
      No Order
    </div>
    <!-- {{orders}} -->
    <div v-else class="flex flex-col gap-2">
      <div
        v-for="(order, index) in orders"
        :key="index"
        class="flex justify-between items-center border relative px-4 rounded-lg bg-white hover:shadow cursor-pointer"
        @click="handleShowDiscountModal(order)"
      >
        <div class="flex flex-col gap-1 py-1">
          <div class="text-sm font-semibold">{{ order.name }}</div>

          <div
            class="text-md flex items-center bg-transparent text-gray-800 border-none shadow-none gap-1"
          >
            <PlusSquareOutlined @click.stop="handleAddOrder(order)" />
            <span>{{ order.quantity }}</span>
            <MinusSquareOutlined @click.stop="handleSubtractOrder(order)" />
          </div>
          <div class="text-[11px]">
            {{ order.price }} x {{ order.quantity }}
          </div>
        </div>
        <div class="text-right flex flex-col  py-1 items-end gap-1">
          <div class="cursor-pointer text-red-700" @click.stop="showVoidItem(order)">
            <CloseOutlined />
          </div>
          <div class="text-xs" v-if="order.discount">
            <div
              class="text-gray-600 border-b px-2"
              v-if="order.discount_type == 'amount'"
            >
              Disc : {{ formattedTotal(order?.discount) }} -
              {{ formattedTotal(order.discount_amount) }}
            </div>
            <div class="text-gray-600 border-b px-2" v-else>
              Disc: {{ formattedPercent(order?.discount) }} -
              {{ formattedTotal(order?.discount_amount) }}
            </div>
          </div>
          <div class="text-xs text-gray-600 line-through invisible" v-else>Discount</div>
          <div
            class="text-md font-semibold text-green-700"
            v-if="order.discount"
          >
            {{ formattedTotal(order.subtotal) }}
          </div>
          <div v-else class="text-md font-semibold text-green-700">
            {{ formattedTotal(order.price * order.quantity) }}
          </div>
        </div>
      </div>
    </div>
  </div>
  <hr class="-mx-6 border-t-[3px] pt-2 mt-2" />
  <div class="font-bold text-lg">
    Total: <span class="text-green-700">{{ formattedTotal(totalAmount) }}</span>
  </div>
  <div class="mt-2">
    <div>Payment Method</div>
    <a-input value="Pay in Cash " disabled></a-input>
  </div>
  <div class="flex gap-2 items-center mt-2">
    <div class="flex-grow text-nowrap">Amount Received :</div>
    <a-input
      :class="{
        'border-red-400 shadow-none':
          amountReceived < totalAmount && orders.length > 0,
      }"
      v-model:value="amountReceived"
      type="number"
    />
  </div>
  <div class="font-bold text-lg mt-2">
    Change:
    <span class="text-green-700">{{ formattedTotal(customerChange) }}</span>
  </div>
  <div>
    <a-button
      class="w-full mt-2"
      :class="disabledPaymentButtonColor"
      type="primary"
      @click="handleProceedPayment"
      :disabled="proceedPaymentLoading || amountReceived < totalAmount"
      :loading="proceedPaymentLoading"
      >Proceed Payment</a-button
    >
  </div>

  <a-modal
    v-model:visible="openvoidModal"
    title="Void Product"
    @cancel="openvoidModal = false"
    :maskClosable="false"
    width="450px"
  >
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    <template #footer>
      <a-button @click="openvoidModal = false">Cancel</a-button>

      <primary-button :loading="loading" @click="handleSubmitVoid"
        >Submit
      </primary-button>
    </template>
  </a-modal>

  <apply-discount-modal
    :openModal="openApplyDiscountModal"
    :product="currentProduct"
    @close="openApplyDiscountModal = false"
  />
</template>