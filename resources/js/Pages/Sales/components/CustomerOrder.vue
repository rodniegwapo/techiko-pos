<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { ref, inject } from "vue";
import {
  CloseOutlined,
  PlusSquareOutlined,
  MinusSquareOutlined,
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useOrders } from "@/Composables/useOrderV2";
import axios from "axios";

const { formData, errors } = useGlobalVariables();
const {
  orders,
  handleAddOrder,
  handleSubtractOrder,
  totalAmount,
  formattedTotal,
  removeOrder,
  orderId,
} = useOrders();

const formFields = [
  { key: "amount", label: "Amount", type: "text", disabled: true },
  { key: "sale_item", label: "Sale item", type: "text", disabled: true },
  { key: "pin_code", label: "Enter Pin", type: "text" },
  {
    key: "reason",
    label: "Reason",
    type: "textarea",
  },
];

const openvoidModal = ref(false);
const showVoidItem = async (product) => {
  openvoidModal.value = true;
  formData.value = {
    ...product,
    sale_item: product.name,
    amount: product.price,
    product_id: product.id,
  };

  return;
  removeOrder(product);
};

const handleSubmitVoid = async () => {
  await axios.post(
    route("sales.item.void", {
      sale: orderId.value,
    }),
    formData.value
  );
};
</script>

<template>
  <div class="space-y-2">
    <div class="font-semibold text-lg">Current Order</div>
    <a-input value="Walk-in Customer" disabled />
  </div>
  <div
    class="flex flex-col gap-2 mt-2 h-[calc(100vh-380px)] overflow-auto overflow-x-hidden"
  >
    <!-- {{orders}} -->
    <div
      v-for="(order, index) in orders"
      :key="index"
      class="flex justify-between items-center border px-4 rounded-lg bg-white hover:shadow cursor-pointer"
    >
      <!-- {{ order }} -->
      <div>
        <div class="text-sm font-semibold">{{ order.name }}</div>

        <div
          class="text-xs flex items-center bg-transparent text-gray-800 border-none shadow-none gap-1 mt-1"
        >
          <PlusSquareOutlined @click="handleAddOrder(order)" />
          <span>{{ order.quantity }}</span>
          <MinusSquareOutlined @click="handleSubtractOrder(order)" />
        </div>
      </div>
      <div class="text-right">
        <div
          class="text-red-600 mt-1 cursor-pointer"
          @click="showVoidItem(order)"
        >
          <CloseOutlined />
        </div>
        <div class="text-xs text-green-700 mt-1">{{ order.price }}</div>
      </div>
    </div>
  </div>
  <hr class="-mx-6 border-t-[3px] pt-2 mt-2" />
  <div class="font-bold text-lg">
    Total: <span class="text-green-700">{{ formattedTotal }}</span>
  </div>
  <div class="mt-2">
    <div>Payment Method</div>
    <a-input value="Pay in Cash " disabled></a-input>
  </div>
  <div>
    <a-button
      class="w-full mt-2 bg-green-700 border-green-700 hover:bg-green-600"
      type="primary"
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

      <primary-button :loading="spinning" @click="handleSubmitVoid"
        >Submit
      </primary-button>
    </template>
  </a-modal>
</template>