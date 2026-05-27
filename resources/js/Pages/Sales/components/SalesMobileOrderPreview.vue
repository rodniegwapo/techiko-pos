<script setup>
import { computed, inject } from "vue";
import { UpOutlined, DownOutlined } from "@ant-design/icons-vue";
import { useStorage } from "@vueuse/core";
import { useHelpers } from "@/Composables/useHelpers";
import { useSaleTotals } from "@/Composables/useSaleTotals";
import { useSalesCartDrawer } from "@/Composables/useSalesCartDrawer";

const ORDER_LINES_ID = "sales-mobile-order-lines";

const props = defineProps({
    orders: { type: Array, default: () => [] },
    orderDiscountAmount: { type: Number, default: 0 },
    salesSettings: { type: Object, default: () => ({}) },
    currentSale: { type: Object, default: () => null },
});

const { formattedTotal } = useHelpers();
const { openCartDrawer } = useSalesCartDrawer();

const previewExpanded = useStorage("sales_mobile_order_preview_expanded", true);

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

function lineAmount(order) {
    const sub = order.subtotal;
    if (sub != null && sub !== "") {
        return Number(sub) || 0;
    }
    const price = parseFloat(order.price) || 0;
    const qty = parseInt(order.quantity, 10) || 0;
    return price * qty;
}

function rowLabel(order) {
    return order?.name ?? "Item";
}

function toggleExpanded() {
    previewExpanded.value = !previewExpanded.value;
}

function openDrawerFromHeader(event) {
    event?.stopPropagation?.();
    openCartDrawer();
}

function onHeaderKeydown(event) {
    if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        openCartDrawer();
    }
}
</script>

<template>
    <div
        class="fixed inset-x-0 bottom-[4.75rem] z-[54] border-t border-gray-200 bg-white shadow-[0_-2px_8px_rgba(0,0,0,0.06)] md:hidden"
        :class="previewExpanded ? 'max-h-[140px]' : ''"
    >
        <div class="flex items-start justify-between gap-2 border-b border-gray-100 px-3 py-2">
            <div
                class="min-w-0 flex-1 cursor-pointer"
                role="button"
                tabindex="0"
                aria-label="Open cart drawer"
                @click="openDrawerFromHeader"
                @keydown="onHeaderKeydown"
            >
                <div class="flex flex-col gap-0.5">
                    <span class="text-xs font-semibold text-gray-600">
                        Current order
                    </span>
                    <span
                        v-if="!previewExpanded"
                        class="text-xs text-gray-500"
                    >
                        {{ itemCount }} items ·
                        {{ formattedTotal(grandTotalDisplay) }}
                    </span>
                    <span
                        v-else-if="itemCount > 0"
                        class="text-xs text-gray-500"
                    >
                        {{ itemCount }} items
                    </span>
                </div>
            </div>
            <a-button
                type="text"
                size="small"
                class="!flex h-8 w-8 shrink-0 items-center justify-center p-0 text-gray-600 hover:text-gray-900"
                :aria-expanded="previewExpanded"
                :aria-controls="ORDER_LINES_ID"
                :aria-label="
                    previewExpanded
                        ? 'Collapse current order list'
                        : 'Expand current order list'
                "
                @click.stop.prevent="toggleExpanded"
            >
                <UpOutlined v-if="previewExpanded" />
                <DownOutlined v-else />
            </a-button>
        </div>
        <div
            v-show="previewExpanded"
            :id="ORDER_LINES_ID"
            class="max-h-[calc(140px-3rem)] overflow-y-auto overflow-x-hidden px-3 pb-2 pt-1"
            role="region"
            aria-label="Order line items"
            @click="openCartDrawer"
        >
            <p
                v-if="!orders.length"
                class="py-3 text-center text-sm text-gray-400"
            >
                No items in order yet
            </p>
            <ul v-else class="space-y-1.5 text-sm">
                <li
                    v-for="(order, index) in orders"
                    :key="order?.id ?? `line-${index}`"
                    class="flex min-w-0 items-center gap-2"
                >
                    <span class="truncate text-gray-800" :title="rowLabel(order)">
                        • {{ rowLabel(order) }}
                    </span>
                    <span class="shrink-0 text-gray-500">
                        ×{{ parseInt(order.quantity, 10) || 0 }}
                    </span>
                    <span class="ml-auto shrink-0 font-medium text-gray-900">
                        {{ formattedTotal(lineAmount(order)) }}
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
