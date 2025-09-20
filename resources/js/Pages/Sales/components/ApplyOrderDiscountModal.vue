<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { onMounted, ref, toRefs } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePage } from "@inertiajs/vue3";
import { useOrders } from "@/Composables/useOrderV2";
import axios from "axios";
import dayjs from "dayjs";

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
    const payload = { discount_id: formData.value?.discount.value };
    loading.value = true;
    const { data: sale } = await axios.post(
      route("sales.discounts.order.apply", {
        sale: orderId.value,
      }),
      payload
    );

    localStorage.setItem(
      "order_discount_amount",
      sale?.sale?.discount_amount ?? 0
    );
    localStorage.setItem("order_discount_id", sale.sale.discount_id);
    
    orderDiscountAmount.value = sale?.sale?.discount_amount ?? 0;
    orderDiscountId.value = sale.sale.discount_id;

    emit("close");
  } catch (e) {
    console.error("Error applying discount:", e);
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

    // backend remove
    await axios.delete(
      route("sales.items.discount.remove", {
        sale: orderId.value,
        saleItem: saleItem?.id,
        discount: product.value.discount_id,
      })
    );

    // update local state (reset)
    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      orders.value[idx] = applyDiscountToLine(orders.value[idx], null);
    }

    emit("close");
  } catch (e) {
    console.error("Error clearing discount:", e);
  } finally {
    discountLoading.value = false;
  }
};

const formFields = [
  {
    key: "discount",
    label: "Select Discount",
    type: "select",
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
        v-if="product.discount"
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
