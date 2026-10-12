<script setup>
import { computed, ref, watch } from "vue";
import { CloseOutlined, PlusOutlined } from "@ant-design/icons-vue";
import { IconArrowBarToDown } from "@tabler/icons-vue";
import CardPaymentTypeModal from "./CardPaymentTypeModal.vue";
import {
    SPLIT_CHANNEL_KIND,
    SPLIT_METHODS,
    newSplitRow,
    splitTotals,
    storeTakesMethod,
} from "@/Composables/useSplitPayment";
import { useHelpers } from "@/Composables/useHelpers";

/** The parts of a sale paid in parts: a method, an amount and (for card / e-wallet / bank) the channel. */
const rows = defineModel({ type: Array, required: true });

const props = defineProps({
    grandTotal: { type: Number, default: 0 },
    allowCredit: { type: Boolean, default: false },
    availableCredit: { type: Number, default: 0 },
    useNetwork: { type: Boolean, default: true },
    cachedPaymentCardTypes: { type: Array, default: () => [] },
    /** Why the sale can't be charged yet (from splitProblem), shown so the cashier knows what's missing. */
    problem: { type: String, default: null },
});

const { formattedTotal } = useHelpers();

const totals = computed(() => splitTotals(rows.value, props.grandTotal));

// The footer already shows what is left and an over-paid card; give every other reason its own line.
const shownProblem = computed(() => {
    if (!props.problem) return null;
    if (totals.value.nonCashOver || props.problem.endsWith("still to pay.")) return null;
    return props.problem;
});

// Only methods this store can take: card / e-wallet / bank need a channel set up at the store.
const methodOptions = computed(() =>
    SPLIT_METHODS.filter((m) =>
        m.value === "credit"
            ? props.allowCredit
            : storeTakesMethod(m.value, props.cachedPaymentCardTypes),
    ),
);

/** What "fill the remaining" would put on this row. */
function restFor(row) {
    const others = rows.value
        .filter((r) => r !== row)
        .reduce((s, r) => s + Math.round(Number(r.amount || 0) * 100), 0);
    return Math.max(0, Math.round(props.grandTotal * 100) - others) / 100;
}

const creditRow = computed(() => rows.value.find((r) => r.method === "credit"));
const creditOverLimit = computed(
    () =>
        creditRow.value &&
        Number(creditRow.value.amount || 0) > Number(props.availableCredit || 0),
);

// One channel picker, opened for whichever row asks.
const pickerRow = ref(null);
const pickerOpen = ref(false);
const pickerKind = computed(
    () => SPLIT_CHANNEL_KIND[pickerRow.value?.method] ?? "card",
);

function pickChannel(row) {
    pickerRow.value = row;
    pickerOpen.value = true;
}

function onPicked(id, channel) {
    if (!pickerRow.value) return;
    pickerRow.value.payment_card_type_id = id;
    pickerRow.value.channel_name =
        channel?.name ??
        props.cachedPaymentCardTypes.find((t) => t.id === id)?.name ??
        null;
}

function onMethodChange(row, method) {
    row.method = method;
    row.payment_card_type_id = null;
    row.channel_name = null;
    if (method === "card" || !SPLIT_CHANNEL_KIND[method]) row.payment_reference = "";
    autoPickChannel(row);
    if (SPLIT_CHANNEL_KIND[method] && !row.payment_card_type_id) pickChannel(row);
}

/** With only one channel of a kind there is nothing to choose, so pick it. */
function autoPickChannel(row) {
    const kind = SPLIT_CHANNEL_KIND[row.method];
    if (!kind || row.payment_card_type_id) return;
    const options = props.cachedPaymentCardTypes.filter((t) => (t.kind || "card") === kind);
    if (options.length === 1) {
        row.payment_card_type_id = options[0].id;
        row.channel_name = options[0].name;
    }
}

watch(
    () => [rows.value.map((r) => r.method), props.cachedPaymentCardTypes],
    () => rows.value.forEach(autoPickChannel),
    { immediate: true, deep: true },
);

/** Put what is still owed on this row. */
function fillRest(row) {
    row.amount = restFor(row);
}

function addRow() {
    if (rows.value.length >= 4) return;
    const hasCash = rows.value.some((r) => r.method === "cash");
    const row = newSplitRow(hasCash ? "e-wallet" : "cash");
    rows.value.push(row);
    fillRest(row);
}

function removeRow(row) {
    if (rows.value.length <= 2) return;
    rows.value.splice(rows.value.indexOf(row), 1);
}
</script>

<template>
    <div class="flex flex-col gap-2" data-testid="split-payment">
        <div
            v-for="(row, i) in rows"
            :key="row.key"
            class="flex flex-wrap items-center gap-2"
        >
            <a-select
                :value="row.method"
                class="w-28"
                :aria-label="`Payment ${i + 1} method`"
                @change="(m) => onMethodChange(row, m)"
            >
                <a-select-option
                    v-for="m in methodOptions"
                    :key="m.value"
                    :value="m.value"
                    >{{ m.label }}</a-select-option
                >
            </a-select>
            <a-input-number
                v-model:value="row.amount"
                :min="0"
                :precision="2"
                placeholder="0.00"
                class="w-32"
                :aria-label="`Payment ${i + 1} amount`"
            />
            <a-tooltip :title="`Fill the remaining ${formattedTotal(restFor(row))}`">
                <a-button
                    size="small"
                    type="text"
                    class="!px-1 text-blue-600"
                    :disabled="Number(row.amount || 0) === restFor(row)"
                    :aria-label="`Fill the remaining amount for payment ${i + 1}`"
                    @click="fillRest(row)"
                >
                    <template #icon><IconArrowBarToDown :size="18" class="inline" /></template>
                </a-button>
            </a-tooltip>
            <template v-if="SPLIT_CHANNEL_KIND[row.method]">
                <a-button
                    size="small"
                    :danger="!row.payment_card_type_id"
                    @click="pickChannel(row)"
                >
                    {{ row.channel_name || "Choose " + (row.method === "card" ? "card type" : row.method) }}
                </a-button>
                <a-input
                    v-if="row.method !== 'card'"
                    v-model:value="row.payment_reference"
                    size="small"
                    :maxlength="100"
                    placeholder="Ref no."
                    class="w-28"
                    :aria-label="`Payment ${i + 1} reference no.`"
                />
            </template>
            <a-button
                v-if="rows.length > 2"
                size="small"
                type="text"
                :aria-label="`Remove payment ${i + 1}`"
                @click="removeRow(row)"
            >
                <CloseOutlined />
            </a-button>
        </div>

        <div class="flex items-center gap-4 text-sm">
            <a-button
                v-if="rows.length < 4"
                size="small"
                type="dashed"
                @click="addRow"
            >
                <PlusOutlined /> Add payment
            </a-button>
            <span v-if="totals.nonCashOver" class="text-red-600 font-medium">
                Only cash can be more than the total
            </span>
            <span v-else-if="totals.remaining > 0" class="text-red-600 font-medium">
                Remaining: {{ formattedTotal(totals.remaining) }}
            </span>
            <span v-else class="text-gray-700">
                Change: <span class="font-medium">{{ formattedTotal(totals.change) }}</span>
            </span>
            <span v-if="creditOverLimit" class="text-red-600">
                Credit is over the available {{ formattedTotal(availableCredit) }}
            </span>
        </div>
        <!-- Why the sale can't be charged yet, so the button is never disabled without a reason. -->
        <p
            v-if="shownProblem"
            class="mb-0 text-sm font-medium text-red-600"
            data-testid="split-problem"
        >
            {{ shownProblem }}
        </p>

        <card-payment-type-modal
            v-model:visible="pickerOpen"
            :use-network="useNetwork"
            :cached-types="cachedPaymentCardTypes"
            :initial-selected-id="pickerRow?.payment_card_type_id"
            :kind="pickerKind"
            @confirm="onPicked"
        />
    </div>
</template>
