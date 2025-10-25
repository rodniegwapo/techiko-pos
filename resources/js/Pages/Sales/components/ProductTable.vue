<script setup>
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { ref, inject } from "vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import axios from "axios";

const props = defineProps({
  products: {
    type: Array,
    default: [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  orders: {
    type: Array,
    default: () => []
  },
  orderId: {
    type: [String, Number],
    default: null
  }
});

const { getRoute } = useDomainRoutes();

// Handle adding items to cart with direct API call
const addToCart = async (product) => {
  try {
    if (!props.orderId) {
      console.error('No active order - cannot add item to cart');
      return;
    }

    const route = getRoute('cart.add', { sale: props.orderId });
    await axios.post(route, {
      product_id: product.id,
      quantity: 1
    });
  } catch (error) {
    console.error('Failed to add item to cart:', error);
  }
};

//  Formatted total with commas (Philippine Peso example)
const formattedTotal = (price) => {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(price);
};
</script>

<template>
  <div class="overflow-y-auto overflow-x-hidden h-[calc(100vh-430px)] relative">
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
            @click="addToCart(product)"
          >
            <PlusSquareOutlined /> Add to Cart
          </a-button>
        </div>
      </div>
    </div>
      <div v-if="products.length ==  0"
      class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
    >
      No Item Found
    </div>
  </div>
</template>