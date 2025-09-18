<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { ref, toRefs } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePage } from "@inertiajs/vue3";
import { useOrders } from "@/Composables/useOrderV2";
import axios from "axios";

const emit = defineEmits(["close"]);
const { formData, errors } = useGlobalVariables();
const page = usePage();
const { orderId, orders, applyDiscountToLine } = useOrders();

const props = defineProps({
  openModal: Boolean,
  product: { type: Object, default: () => ({}) },
});
const { openModal, product } = toRefs(props);

const loading = ref(false);

/** 🔹 Apply discount */
const handleSave = async () => {
  try {
    if (!formData.value?.discount?.value) return emit("close");
    loading.value = true;

    // fetch sale item
    const { data: saleItem } = await axios.get(
      route("sales.find-sale-item", { sale: orderId.value }),
      { params: { product_id: product.value.id } }
    );

    // selected discount
    const selectedDiscount = page.props.discounts.find(
      (d) => d.id === formData.value.discount.value
    );
    if (!selectedDiscount) return emit("close");

    // backend apply
    await axios.post(
      route("sales.items.discount.apply", {
        sale: orderId.value,
        saleItem: saleItem?.id,
      }),
      { discount_id: selectedDiscount.id }
    );

    // update local state
    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      orders.value[idx] = applyDiscountToLine(
        orders.value[idx],
        selectedDiscount
      );
    }

    emit("close");
  } catch (e) {
    console.error("Error applying discount:", e);
  } finally {
    loading.value = false;
  }
};

/**  Clear discount */
const discountLoading = ref(false)
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
        discount: product.value.discount_id
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
      .filter((item) => item.scope == "product")
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
    :title="`Apply Discount`"
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
