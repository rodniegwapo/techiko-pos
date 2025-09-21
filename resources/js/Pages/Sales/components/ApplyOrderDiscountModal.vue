<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { onMounted, ref, toRefs } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePage } from "@inertiajs/vue3";
import { useOrders } from "@/Composables/useOrderV2";
import axios from "axios";
import dayjs from "dayjs";
import { data } from "autoprefixer";

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

    emit("close");
  } catch (e) {
    console.error("Error applying discount:", e);
  } finally {
    loading.value = false;
  }
};

const checkedForm = (dataForm) => {
  if (!dataForm.orderDiscount) return false;
  const values = dataForm.orderDiscount.filter((item) =>
    item.hasOwnProperty("value")
  );

  if (values.length == 0) return false;

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

    emit("close");
  } catch (e) {
    console.error("Error clearing discount:", e);
  } finally {
    discountLoading.value = false;
  }
};

const formFields = [
  {
    key: "orderDiscount",
    label: "Select Discount",
    type: "select",
    isAllowClear: false,
    multiple: true,
    options: page.props.discounts
      .filter(
        (item) =>
          item.scope == "order" &&
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
    :title="`Apply Order Discount`"
    @cancel="$emit('close')"
    width="400px"
  >
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
      <primary-button :loading="loading" @click="handleSave">
        Submit
      </primary-button>
    </template>
  </a-modal>
</template>
