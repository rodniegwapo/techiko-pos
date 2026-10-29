<script setup>
import { computed, reactive, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * The cash count: type the total, or (in pesos) count it bill by bill and coin by coin. Only the
 * total is saved. Used by the spot check and when a shift or the day ends, so counting looks the
 * same everywhere.
 */
const props = defineProps({
    /** The counted total (v-model). */
    modelValue: { type: [Number, String], default: null },
    placeholder: { type: String, default: "Count the cash" },
    /** Test ids: `${testid}` on the total, `${prefix}-by-bill` and `${prefix}-bills` on the counter. */
    testid: { type: String, required: true },
    prefix: { type: String, required: true },
});
const emit = defineEmits(["update:modelValue"]);

const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.25];
const page = usePage();
const canCountByBill = computed(() => (page.props.currentDomain?.currency_code || "PHP") === "PHP");
const byBill = ref(false);
const pieces = reactive(Object.fromEntries(DENOMINATIONS.map((d) => [d, null])));
const billTotal = computed(() => Math.round(DENOMINATIONS.reduce((sum, d) => sum + d * Number(pieces[d] || 0), 0) * 100) / 100);
watch(billTotal, (total) => {
    if (byBill.value) emit("update:modelValue", total);
});
function toggleByBill() {
    byBill.value = !byBill.value;
    if (byBill.value) emit("update:modelValue", billTotal.value);
}
const denominationLabel = (d) => (d < 1 ? `${Math.round(d * 100)}¢` : `₱${d.toLocaleString()}`);
</script>

<template>
    <div>
        <a-input-number
            :value="modelValue"
            :min="0"
            :step="0.01"
            :precision="2"
            class="w-full"
            addon-before="₱"
            :placeholder="placeholder"
            :disabled="byBill"
            :data-testid="testid"
            @update:value="(v) => emit('update:modelValue', v)"
        />
        <a-button v-if="canCountByBill" type="link" size="small" class="h-auto !px-0" :data-testid="`${prefix}-by-bill`" @click="toggleByBill">
            {{ byBill ? "Type the total instead" : "Count by bills and coins" }}
        </a-button>
        <div v-if="byBill" class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-3" :data-testid="`${prefix}-bills`">
            <a-input-number
                v-for="d in DENOMINATIONS"
                :key="d"
                v-model:value="pieces[d]"
                :min="0"
                :precision="0"
                :addon-before="denominationLabel(d)"
                placeholder="0"
                class="!w-full"
                :aria-label="`Number of ${denominationLabel(d)}`"
            />
        </div>
    </div>
</template>
