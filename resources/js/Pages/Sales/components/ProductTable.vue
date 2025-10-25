<script setup>
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { ref, inject } from "vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePage } from "@inertiajs/vue3";
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
        default: () => [],
    },
    orderId: {
        type: [String, Number],
        default: null,
    },
});

const { getRoute } = useDomainRoutes();
const page = usePage();

// Emit events to parent
const emit = defineEmits(['cart-updated']);

// Handle adding items to cart with direct API call
const loading = ref(false);
const addToCart = async (product) => {
    try {
        loading.value = true;
        console.log("addToCart called with:", {
            product,
            orderId: props.orderId,
        });

        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.add", { user: userId });
        console.log("Generated route:", route);

        await axios.post(route, {
            product_id: product.id,
            quantity: 1,
        });

        console.log("Successfully added item to cart");
        
        // Emit event to parent to refresh cart data
        emit('cart-updated');
    } catch (error) {
        console.error("Failed to add item to cart:", error);
    } finally {
        loading.value = false;
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
    <div
        class="overflow-y-auto overflow-x-hidden h-[calc(100vh-430px)] relative"
    >
        <a-spin
            v-if="loading"
            class="-rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
            size="large"
        />
        <div
            v-else
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
                        :disabled="loading"
                    >
                        <PlusSquareOutlined /> Add to Cart
                    </a-button>
                </div>
            </div>
        </div>
        <div
            v-if="products.length == 0"
            class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
        >
            No Item Found
        </div>
    </div>
</template>
