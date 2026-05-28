<script setup>
import { computed, inject } from "vue";
import { ShoppingCartOutlined } from "@ant-design/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useSaleTotals } from "@/Composables/useSaleTotals";
import { useSalesCartDrawer } from "@/Composables/useSalesCartDrawer";

const props = defineProps({
    orders: { type: Array, default: () => [] },
    orderDiscountAmount: { type: Number, default: 0 },
    salesSettings: { type: Object, default: () => ({}) },
    currentSale: { type: Object, default: null },
});

const { formattedTotal } = useHelpers();
const { openCartDrawer } = useSalesCartDrawer();

const salesCartIsOnline = inject(
    "isSalesOnline",
    computed(() => true),
);

const { itemCount, grandTotalDisplay } = useSaleTotals({
    orders: computed(() => props.orders),
    orderDiscountAmount: computed(() => props.orderDiscountAmount),
    salesSettings: computed(() => props.salesSettings),
    currentSale: computed(() => props.currentSale),
    salesCartIsOnline,
});
</script>

<template>
    <div
        class="fixed inset-x-0 bottom-0 z-[55] border-t border-gray-300 bg-white px-3 py-2.5 shadow-[0_-4px_12px_rgba(0,0,0,0.08)] md:hidden"
        style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom, 0px))"
    >
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-gray-500">Total</p>
                <p class="truncate text-lg font-bold text-green-600">
                    {{ formattedTotal(grandTotalDisplay) }}
                </p>
            </div>
            <a-badge
                :count="itemCount"
                :overflow-count="99"
                :show-zero="false"
                class="inline-flex shrink-0"
            >
                <a-button type="primary" @click="openCartDrawer">
                    <template #icon>
                        <ShoppingCartOutlined />
                    </template>
                    Cart
                </a-button>
            </a-badge>
        </div>
    </div>
</template>
