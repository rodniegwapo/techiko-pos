<script setup>
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { ref, inject, computed } from "vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { notifyInsufficientStock } from "@/Composables/useCartStockNotification";
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
    loadingMore: {
        type: Boolean,
        default: false,
    },
    hasMoreProducts: {
        type: Boolean,
        default: false,
    },
    productsTotal: {
        type: Number,
        default: 0,
    },
    salesCartIsOnline: {
        type: Boolean,
        default: true,
    },
    orders: {
        type: Array,
        default: () => [],
    },
    orderId: {
        type: [String, Number],
        default: null,
    },
    layout: {
        type: String,
        default: "desktop",
        validator: (value) => ["desktop", "mobile"].includes(value),
    },
});

const { getRoute } = useDomainRoutes();
const page = usePage();

const salesCartIsOnlineInject = inject(
    "isSalesOnline",
    computed(() => true),
);

const online = computed(() =>
    typeof props.salesCartIsOnline === "boolean"
        ? props.salesCartIsOnline
        : salesCartIsOnlineInject.value,
);

const emit = defineEmits(["cart-updated", "offline-add-product", "load-more"]);

const addingItem = ref(false);
const addToCart = async (product) => {
    try {
        addingItem.value = true;
        if (!online.value) {
            emit("offline-add-product", product);
            return;
        }

        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.add", { user: userId });

        await axios.post(route, {
            product_id: product.id,
            quantity: 1,
        });

        emit("cart-updated");
    } catch (error) {
        notifyInsufficientStock(error);
        console.error("Failed to add item to cart:", error);
    } finally {
        addingItem.value = false;
    }
};

const formattedTotal = (price) => {
    return new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
    }).format(price);
};
</script>

<template>
    <div
        class="flex flex-col min-h-0"
        :class="
            layout === 'mobile'
                ? 'h-[calc(100dvh-16rem)]'
                : 'h-[calc(100vh-430px)]'
        "
    >
        <div class="overflow-y-auto overflow-x-hidden flex-1 min-h-0 relative">
            <a-spin
                v-if="props.loading"
                class="-rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
                size="large"
            />
            <div
                v-else-if="products.length"
                class="grid [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))] gap-4 mt-2"
            >
                <div
                    v-for="product in products"
                    :key="product.id"
                    class="flex justify-between items-start border px-4 py-3 rounded-lg bg-white hover:shadow cursor-pointer"
                >
                    <div>
                        <div class="text-sm font-semibold">{{ product.name }}</div>
                        <div
                            class="text-[10px] text-gray-400 w-fit mt-0.5"
                            v-if="product.SKU"
                        >
                            SKU {{ product.SKU }}
                        </div>
                        <div
                            class="text-[10px] text-gray-300 bg-gray-600 w-fit px-2 py-[1px] rounded-full mt-1"
                        >
                            {{ product?.category?.name ?? "Uncategorized" }}
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
                            :disabled="addingItem"
                        >
                            <PlusSquareOutlined /> Add to Cart
                        </a-button>
                    </div>
                </div>
            </div>
            <div
                v-else-if="!online"
                class="flex items-center justify-center h-full min-h-[200px] text-center text-gray-500 text-sm px-6"
            >
                No offline catalog loaded. While online, use &quot;Sync for offline&quot;
                on Sales, or add items via barcode from a prior session.
            </div>
            <div
                v-else
                class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
            >
                No Item Found
            </div>
        </div>

        <div
            v-if="online && products.length && props.productsTotal > 0"
            class="shrink-0 pt-3 pb-1 text-xs text-gray-500 text-center border-t border-gray-100"
        >
            Showing {{ products.length }} of {{ props.productsTotal }}
        </div>
        <div
            v-if="online && props.hasMoreProducts && products.length"
            class="shrink-0 pb-2 flex justify-center"
        >
            <a-button
                type="default"
                :loading="props.loadingMore"
                :disabled="props.loading"
                @click="emit('load-more')"
            >
                Load more
            </a-button>
        </div>
    </div>
</template>
