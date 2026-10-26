<script setup>
import { computed, ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";
import { toQuantity } from "@/Utils/quantity";

/**
 * "How much?" for a product sold by weight or volume: the cashier types what the scale shows
 * (1.5 kg), or taps a common amount. The price follows as they type.
 */
const props = defineProps({
    product: { type: Object, default: null },
});
const emit = defineEmits(["close", "confirm"]);
const { formattedTotal } = useHelpers();

const amount = ref(null);
watch(
    () => props.product,
    (p) => {
        if (p) amount.value = null;
    },
);

const unit = computed(() => props.product?.quantity_unit ?? "");
const quick = [0.25, 0.5, 1, 2];
const price = computed(() => toQuantity(amount.value) * (parseFloat(props.product?.price) || 0));
const valid = computed(() => toQuantity(amount.value) >= 0.001);

function confirm() {
    if (valid.value) emit("confirm", toQuantity(amount.value));
}
</script>

<template>
    <a-modal
        :visible="!!product"
        :title="product ? `How much ${product.name}?` : ''"
        ok-text="Add to order"
        :ok-button-props="{ disabled: !valid, 'data-testid': 'quantity-prompt-add' }"
        :width="380"
        destroy-on-close
        @ok="confirm"
        @cancel="emit('close')"
    >
        <div v-if="product" class="flex flex-col gap-3 pt-1" data-testid="quantity-prompt">
            <a-input-number
                v-model:value="amount"
                :min="0.001"
                :step="0.25"
                :precision="3"
                :addon-after="unit"
                size="large"
                class="w-full"
                autofocus
                placeholder="e.g. 1.5"
                data-testid="quantity-prompt-amount"
                @press-enter="confirm"
            />
            <div class="flex flex-wrap gap-2">
                <a-button v-for="q in quick" :key="q" size="small" @click="amount = q">{{ q }} {{ unit }}</a-button>
            </div>
            <p class="m-0 text-sm text-gray-600">
                {{ formattedTotal(parseFloat(product.price) || 0) }} per {{ unit }} ·
                <span class="font-semibold text-gray-900" data-testid="quantity-prompt-price">{{ formattedTotal(price) }}</span>
            </p>
        </div>
    </a-modal>
</template>
