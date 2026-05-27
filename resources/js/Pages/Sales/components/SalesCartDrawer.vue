<script setup>
import { useSalesCartDrawer } from "@/Composables/useSalesCartDrawer";

const { cartDrawerOpen, closeCartDrawer } = useSalesCartDrawer();
</script>

<template>
    <a-drawer
        v-model:visible="cartDrawerOpen"
        title="Current order"
        placement="bottom"
        :height="'90dvh'"
        :destroy-on-close="false"
        :body-style="{
            padding: 0,
            display: 'flex',
            flexDirection: 'column',
            height: '100%',
            overflow: 'hidden',
        }"
        wrap-class-name="sales-cart-drawer-wrap md:hidden"
        @close="closeCartDrawer"
    >
        <div class="flex h-full min-h-0 flex-col">
            <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                <slot name="cart" />
            </div>
            <div class="shrink-0 border-t border-gray-200 bg-white">
                <slot name="checkout" />
            </div>
        </div>
    </a-drawer>
</template>
