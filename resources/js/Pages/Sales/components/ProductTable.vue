<script setup>
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { ref, inject } from "vue";
import { useOrders } from "@/Composables/useOrderV2";

const props = defineProps({
  products: {
    type: Array,
    default: [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

const { orders, handleAddOrder } = useOrders();

//  Formatted total with commas (Philippine Peso example)
const formattedTotal = (price) => {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(price);
};
</script>

<template>
  <div class="overflow-y-auto overflow-x-hidden h-[calc(100vh-300px)] relative">
    <a-spin v-if="loading"
      class="-rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
      size="large"
    />
    <div v-else
      class="grid [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))] gap-4 mt-2"
    >
      <div
        v-for="(product, index) in products"
        :key="index"
        class="flex justify-between items-start border px-4 py-3 rounded-lg bg-white hover:shadow cursor-pointer"
      >
        <div>
          <div class="text-sm font-semibold">{{ product.name }}</div>
          <div
            class="text-[10px] text-gray-300 bg-gray-600 w-fit px-2 py-[1px] rounded-full mt-1"
          >
            {{ product?.category?.name }}
          </div>
        </div>
        <div class="text-right">
          <div class="text-md text-green-700 font-bold">
            {{ formattedTotal(product.price) }}
          </div>
          <a-button
            type="primary"
            class="text-xs flex items-center p-0 mt-1 bg-transparent text-gray-800 border-none shadow-none"
            size="small"
            @click="handleAddOrder(product)"
          >
            <PlusSquareOutlined /> Add to Cart
          </a-button>
        </div>
      </div>
    </div>
  </div>
</template>