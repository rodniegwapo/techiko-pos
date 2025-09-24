<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import ApplyProductDiscountModal from "./ApplyProductDiscountModal.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { ref, inject, computed, createVNode } from "vue";
import {
  IconArmchair,
  IconUsers,
} from "@tabler/icons-vue";
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
  formData.value = {
    discount: product.discount_id,
  };
  currentProduct.value = product;
  openApplyDiscountModal.value = true;
};

const isLoyalCustomer = ref(false);
const customer = ref("");

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

const cardClass =
  "w-1/2 border shadow text-center flex justify-center rounded-lg items-center gap-2 p-2 hover:bg-blue-400 hover:text-white  cursor-pointer";


const showPayment = ref(false);
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
  <div class="mt-2">
    <div class="font-semibold">Quick Discounts</div>
    <div class="flex items-center gap-2 mt-1">
      <div :class="cardClass">
        <div><IconArmchair size="20" /></div>
        <div class="text-left text-xs">
          <div>PWD</div>
          <div>20% Off</div>
        </div>
      </div>
      <div :class="cardClass">
        <div><IconUsers size="20" /></div>
        <div class="text-left text-xs">
          <div>Senior</div>
          <div>20% Off</div>
        </div>
      </div>
    </div>
  </div>

  <div class="relative">
    <!-- Transition wrapper -->
    <Transition name="slide-x" mode="out-in">
      <!-- 🟥 ORDER SUMMARY PAGE -->
      <div v-if="!showPayment" key="order" >
        <div
          class="relative flex flex-col gap-2 mt-4 h-[calc(100vh-420px)] overflow-auto overflow-x-hidden"
        >
          <div
            v-if="orders.length == 0"
            class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
          >
            No Order
          </div>

          <div v-else class="flex flex-col gap-2">
            <div
              v-for="(order, index) in orders"
              :key="index"
              class="flex justify-between items-center border relative px-4 rounded-lg bg-white hover:shadow cursor-pointer"
              @click="handleShowProductDiscountModal(order)"
            >
              <div class="flex flex-col gap-1 py-1">
                <div class="text-sm font-semibold">{{ order.name }}</div>

                <div class="text-md flex items-center gap-1 text-gray-800">
                  <PlusSquareOutlined @click.stop="handleAddOrder(order)" />
                  <span>{{ order.quantity }}</span>
                  <MinusSquareOutlined
                    @click.stop="handleSubtractOrder(order)"
                  />
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
                <div
                  class="text-xs text-gray-600 line-through invisible"
                  v-else
                >
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
      </div>

    </Transition>
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
<style>
/* Slide horizontal animation */
.slide-x-enter-active,
.slide-x-leave-active {
  transition: all 0.3s ease;
  position: absolute;
  width: 100%;
}
.slide-x-enter-from {
  opacity: 0;
  transform: translateX(100%);
}
.slide-x-leave-to {
  opacity: 0;
  transform: translateX(-100%);
}
</style>
