<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import ApplyProductDiscountModal from "./ApplyProductDiscountModal.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { ref, inject, computed, createVNode } from "vue";
import { IconDiscount } from "@tabler/icons-vue";
import {
  CloseOutlined,
  PlusSquareOutlined,
  MinusSquareOutlined,
  ExclamationCircleOutlined,
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useOrders } from "@/Composables/useOrderV2";
import { useHelpers } from "@/Composables/useHelpers";
import axios from "axios";
import { Modal, notification } from "ant-design-vue";

const { formData, errors } = useGlobalVariables();
const {
  orders,
  orderDiscountAmount,
  orderDiscountId,
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
  return received - (total - orderDiscountAmount.value);
});
const handleProceedPaymentConfirmation = () => {
  Modal.confirm({
    title: "Are you sure you would like to proceed?",
    icon: createVNode(ExclamationCircleOutlined),
    okText: "Submit",
    cancelText: "Cancel",
    onOk() {
      return new Promise(async (innerResolve, innerReject) => {
        try {
          await handleProceedPayment(); // wait until payment success
          innerResolve(); // close modal
        } catch (error) {
          innerReject(error); // keep modal open if failed
        }
      });
    },
    onCancel() {
      console.log("Cancel");
    },
  });
};


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
    localStorage.setItem("order_discount_amount", 0);
    localStorage.setItem("order_discount_id", '');
    orderDiscountAmount.value = 0;
    orderDiscountId.value = "";
  } catch (error) {
    notification["error"]({
      message: "Payment failed",
    });
    throw error; // bubble up to keep modal open
  } finally {
    proceedPaymentLoading.value = false;
  }
};

const disabledPaymentButtonColor = computed(() => {
  if (amountReceived.value < totalAmount.value) return "";
  if (orders.value.length == 0) return "";
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
const handleShowProductDiscountModal = (product) => {
  console.log('order discount',product)
  formData.value = {
    discount: product.discount_id
  };
  currentProduct.value = product;
  openApplyDiscountModal.value = true;
};

const isLoyalCustomer = ref(false);
const customer = ref("");

const openOrderDicountModal = ref(false);

const showDiscountOrder = () => {
  if(orders.value.length == 0) return
   formData.value = {
    orderDiscount: orderDiscountId.value ? Number(orderDiscountId.value) : ''
  };
  openOrderDicountModal.value = true
}
</script>

<template>
  <div class="flex items-center justify-between">
    <div class="font-semibold text-lg">Current Order</div>
    <a-switch
      v-model:checked="isLoyalCustomer"
      checked-children="Loyal"
      un-checked-children="Walk-in"
    />
  </div>
  <div class="mt-1">
    <a-input-search
      v-if="isLoyalCustomer"
      v-model:value="customer"
      placeholder="Search Customer"
    />
    <a-input v-else value="Walk-in Customer" disabled />
  </div>
  <div
    class="relative flex flex-col gap-2 mt-2 h-[calc(100vh-450px)] overflow-auto overflow-x-hidden"
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
        @click="handleShowProductDiscountModal(order)"
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
        <div class="text-right flex flex-col py-1 items-end gap-1">
          <div
            class="cursor-pointer text-red-700"
            @click.stop="showVoidItem(order)"
          >
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
          <div class="text-xs text-gray-600 line-through invisible" v-else>
            Discount
          </div>
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
  <div class="relative">
    <div class="flex justify-between items-center">
      <div class="text-xs">
        <span class="font-semibold">Order Discount: </span>
        <span class="text-gray-700">{{
          formattedTotal(orderDiscountAmount)
        }}</span>
      </div>
      <div>
        <icon-tooltip-button
          name="Apply Order Discount"
          class="hover:bg-green-700"
          :disabled="orders.length == 0"
          @click="showDiscountOrder"
        >
          <IconDiscount size="20" class="mx-auto" />
        </icon-tooltip-button>
      </div>
    </div>
    <div class="text-xs">
      <span class="font-semibold">Subtotal:</span>
      {{ formattedTotal(totalAmount) }}
    </div>
    <div class="font-bold text-lg flex items-center justify-between mt-2">
      <div>
        Total:
        <span class="text-green-700"
          >{{ formattedTotal(totalAmount - Number(orderDiscountAmount)) }}
        </span>
      </div>
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
            amountReceived < totalAmount - orderDiscountAmount &&
            orders.length > 0,
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
        @click="handleProceedPaymentConfirmation"
        :disabled="
          proceedPaymentLoading ||
          amountReceived < totalAmount - orderDiscountAmount ||
          orders.length == 0
        "
        :loading="proceedPaymentLoading"
        >Proceed Payment</a-button
      >
    </div>
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

  <apply-product-discount-modal
    :openModal="openApplyDiscountModal"
    :product="currentProduct"
    @close="openApplyDiscountModal = false"
  />

  <apply-order-discount-modal
    :openModal="openOrderDicountModal"
    :product="currentProduct"
    @close="openOrderDicountModal = false"
  />
</template>