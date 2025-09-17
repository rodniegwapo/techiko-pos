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
const { orderId, orders } = useOrders();

const props = defineProps({
  openModal: { type: Boolean, default: false },
  product: { type: Object, default: {} },
});

const { openModal, product } = toRefs(props);
const loading = ref(false);

const handleSave = async () => {
  try {
    // ✅ Ensure formData is a valid object
    if (!formData.value || typeof formData.value !== "object") {
      emit("close");
      return;
    }

    loading.value = true;

    // fetch sale item
    const saleItem = await axios.get(
      route("sales.find-sale-item", { sale: orderId.value }),
      { params: { product_id: product.value.id } }
    );

    // get selected discount
    const selectedDiscount = page.props.discounts.find(
      (d) => d.id === formData.value?.discount?.value
    );

    console.log('sale items',saleItem)

    if (!selectedDiscount) {
      emit("close");
      return;
    }


    // prepare payload for backend
    const payload = { discount_id: selectedDiscount.id };

    await axios.post(
      route("sales.items.discount.apply", {
        sale: orderId.value,
        saleItem: saleItem.data?.id
      }),
      payload
    );

    const idx = orders.value.findIndex((item) => item.id == product.value.id);
    if (idx !== -1) {
      const lineSubtotal = orders.value[idx].price * orders.value[idx].quantity;

      let discountAmount = 0;
      if (selectedDiscount.type == "percentage") {
        const percentage = Math.min(Math.max(selectedDiscount.value, 0), 100);
        discountAmount = lineSubtotal * (percentage / 100);
      } else if (selectedDiscount.type === "amount") {
        discountAmount = Math.min(
          Math.max(selectedDiscount.value, 0),
          lineSubtotal
        );
      }

      orders.value[idx] = {
        ...orders.value[idx],
        discount_id: selectedDiscount.id,
        discount_amount: discountAmount,
        discount_type: selectedDiscount.type,
        discount: selectedDiscount.value,
        subtotal: lineSubtotal - discountAmount,
      };
    }

    console.log("orders after save:", orders.value);
    emit("close");
  } catch (error) {
    console.error("Error applying discount:", error);
  } finally {
    loading.value = false;
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
  <div>
    <a-modal
      v-model:visible="openModal"
      :title="`Apply Discount to`"
      @cancel="$emit('close')"
      width="400px"
    >
      <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
      <template #footer>
        <a-button @click="$emit('close')">Cancel</a-button>
        <primary-button :loading="loading" @click="handleSave">
          Submit
        </primary-button>
      </template>
    </a-modal>
  </div>
</template>
