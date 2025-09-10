<script setup>
import { PlusSquareOutlined } from "@ant-design/icons-vue";
const props = defineProps({
  products: {
    type: Array,
    default: [],
  },
});
</script>

<template>
  <div class="overflow-y-auto overflow-x-hidden h-[calc(100vh-250px)]">
    <div class="grid [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))] gap-4 mt-2">
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
            ₱ {{ parseFloat(product.price) }}
          </div>
          <a-button
            type="primary"
            class="text-xs flex items-center p-0 mt-1 bg-transparent text-gray-800 border-none shadow-none"
            size="small"
          >
            <PlusSquareOutlined /> Add to Cart
          </a-button>
        </div>
      </div>
    </div>
  </div>
</template>