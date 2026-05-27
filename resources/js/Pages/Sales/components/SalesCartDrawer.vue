<script setup>
import { computed, watch, nextTick } from "vue";
import { LeftOutlined } from "@ant-design/icons-vue";
import { useSalesCartDrawer } from "@/Composables/useSalesCartDrawer";

const {
    cartDrawerOpen,
    checkoutStep,
    closeCartDrawer,
    goToOrderStep,
} = useSalesCartDrawer();

const drawerTitle = computed(() =>
    checkoutStep.value === "payment" ? "Checkout" : "Current order",
);

watch(
    [checkoutStep, cartDrawerOpen],
    ([step, open]) => {
        if (step !== "payment" || !open) return;
        nextTick(() => {
            const pane = document.querySelector("#sales-cart-payment-pane");
            const amountInput =
                pane?.querySelector('input[placeholder="0"]') ||
                pane?.querySelector("input.ant-input");
            if (
                amountInput &&
                typeof amountInput.focus === "function" &&
                !amountInput.hasAttribute("disabled")
            ) {
                amountInput.focus();
            }
        });
    },
);
</script>

<template>
    <a-drawer
        v-model:visible="cartDrawerOpen"
        placement="bottom"
        :height="'90dvh'"
        :destroy-on-close="false"
        class="sales-cart-drawer-wrap md:hidden"
        :body-style="{
            padding: 0,
            display: 'flex',
            flexDirection: 'column',
            height: '100%',
            overflow: 'hidden',
        }"
        @close="closeCartDrawer"
    >
        <template #title>
            <div class="flex items-center gap-1 pr-2">
                <a-button
                    v-if="checkoutStep === 'payment'"
                    type="text"
                    class="flex h-8 min-w-8 items-center justify-center !p-0"
                    aria-label="Back to order"
                    @click="goToOrderStep"
                >
                    <LeftOutlined class="text-base" />
                </a-button>
                <span class="text-base font-medium">{{ drawerTitle }}</span>
            </div>
        </template>
        <div class="flex h-full min-h-0 flex-col">
            <!-- Full-width stacked panes (avoids 200% flex track width bugs on some mobile WebViews) -->
            <div class="relative min-h-0 flex-1 overflow-hidden min-w-0">
                <div
                    class="absolute inset-0 flex min-h-0 flex-col overflow-hidden bg-white transition-transform duration-300 ease-out will-change-transform"
                    :class="[
                        checkoutStep === 'payment'
                            ? '-translate-x-full'
                            : 'translate-x-0',
                        checkoutStep !== 'order' ? 'pointer-events-none' : '',
                    ]"
                >
                    <slot name="cart" />
                </div>
                <div
                    id="sales-cart-payment-pane"
                    class="absolute inset-0 flex min-h-0 flex-col overflow-hidden bg-white transition-transform duration-300 ease-out will-change-transform"
                    :class="[
                        checkoutStep === 'payment'
                            ? 'translate-x-0'
                            : 'translate-x-full',
                        checkoutStep !== 'payment' ? 'pointer-events-none' : '',
                    ]"
                >
                    <div
                        class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden"
                    >
                        <slot name="payment" />
                    </div>
                </div>
            </div>
            <div
                v-show="checkoutStep === 'order'"
                class="shrink-0 border-t border-gray-200 bg-white"
            >
                <slot name="drawer-footer" />
            </div>
        </div>
    </a-drawer>
</template>
