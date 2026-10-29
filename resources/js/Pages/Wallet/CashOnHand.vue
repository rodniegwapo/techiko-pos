<script setup>
import { computed, reactive, ref } from "vue";
import dayjs from "dayjs";
import { message as antMessage } from "ant-design-vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import WalletLayout from "./components/WalletLayout.vue";
import CollectShiftModal from "./components/cash/CollectShiftModal.vue";
import { CASH_ON_HAND_KIND_LABELS } from "./components/walletLabels";

/**
 * Cash on hand: the business's cash the owner holds, out of every drawer. Still the business's
 * money, so collecting it and moving it back to a store don't change the books; only "Owner used
 * it" takes it out of the business. For owners and admins.
 */
const props = defineProps({
    balance: { type: Number, default: 0 },
    /** Approved shifts not collected yet (toRow, plus store and location_id). */
    waiting: { type: Array, default: () => [] },
    lines: { type: Object, required: true },
    /** [{ id, name, channels: [{ id, name }] }]: where "Move to a store" can go. */
    stores: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const money = (v) => formattedTotal(Number(v) || 0);

const collecting = ref(null);
const waitingTotal = computed(() => props.waiting.reduce((sum, s) => sum + (Number(s.counted_cash) || 0), 0));

// Move to a store, Owner used it, or a correction.
const ACTIONS = {
    to_store: { title: "Move to a store", ok: "Move" },
    owner_use: { title: "Owner used it", ok: "Save" },
    adjustment: { title: "Correction", ok: "Save" },
};
const mode = ref(null);
const form = reactive({ amount: null, store: null, place: "drawer", direction: "in", notes: "", saving: false });
function open(action) {
    Object.assign(form, { amount: null, store: props.stores[0]?.id ?? null, place: "drawer", direction: "in", notes: "", saving: false });
    mode.value = action;
}
const placeOptions = computed(() => [
    { value: "drawer", label: "Cash drawer" },
    ...(props.stores.find((s) => s.id === form.store)?.channels ?? []).map((c) => ({ value: c.id, label: c.name })),
]);
const overBalance = computed(() => (mode.value !== "adjustment" || form.direction === "out") && Number(form.amount) > props.balance);

function save() {
    if (!(Number(form.amount) >= 0.01)) return antMessage.warning("Enter the amount.");
    if (mode.value === "adjustment" && !form.notes.trim()) return antMessage.warning("Say what the correction is for.");
    form.saving = true;
    router.post(
        getRoute("cash-on-hand.store"),
        {
            action: mode.value,
            amount: Number(form.amount),
            location_id: mode.value === "to_store" ? form.store : null,
            payment_card_type_id: mode.value === "to_store" && form.place !== "drawer" ? form.place : null,
            direction: mode.value === "adjustment" ? form.direction : null,
            notes: form.notes.trim() || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                antMessage.success("Saved.");
                mode.value = null;
            },
            onError: (errors) => antMessage.error(Object.values(errors ?? {})[0] || "Could not save."),
            onFinish: () => (form.saving = false),
        },
    );
}

const range = ref([props.filters.date_from ? dayjs(props.filters.date_from) : null, props.filters.date_to ? dayjs(props.filters.date_to) : null]);
function filter(value) {
    router.get(
        getRoute("cash-on-hand.index"),
        value?.[0] && value?.[1] ? { date_from: value[0].format("YYYY-MM-DD"), date_to: value[1].format("YYYY-MM-DD") } : {},
        { preserveScroll: true, preserveState: true },
    );
}

const columns = [
    { title: "Date", key: "date" },
    { title: "What", key: "what" },
    { title: "By", key: "by" },
    { title: "In / out", key: "amount", align: "right" },
    { title: "Cash on hand", key: "balance", align: "right" },
];
const pagination = computed(() => ({
    current: props.lines.current_page,
    pageSize: props.lines.per_page,
    total: props.lines.total,
    showSizeChanger: false,
}));
function changePage(p) {
    router.get(getRoute("cash-on-hand.index"), { ...props.filters, page: p.current }, { preserveScroll: true });
}
</script>

<template>
    <WalletLayout title="Cash on hand" active="cash-on-hand">
        <div class="mb-5 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4 md:col-span-1" data-testid="cash-on-hand-balance">
                <div class="text-xs uppercase text-gray-500">Cash on hand</div>
                <div class="font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ money(balance) }}</div>
                <p class="mb-0 mt-1 text-xs text-gray-500">
                    The business's cash the owner collected from the drawers. It's still the business's money, only somewhere else.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a-button type="primary" size="small" :disabled="balance <= 0" data-testid="cash-on-hand-to-store" @click="open('to_store')">
                        Move to a store
                    </a-button>
                    <a-button size="small" :disabled="balance <= 0" data-testid="cash-on-hand-owner-use" @click="open('owner_use')">Owner used it</a-button>
                    <a-button size="small" data-testid="cash-on-hand-adjust" @click="open('adjustment')">Correction</a-button>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 md:col-span-2" data-testid="cash-on-hand-waiting">
                <div class="mb-2 flex items-baseline justify-between gap-2">
                    <div class="text-sm font-semibold text-gray-800">Waiting to collect</div>
                    <div v-if="waiting.length" class="text-xs text-gray-500">{{ waiting.length }} shift(s), {{ money(waitingTotal) }} counted</div>
                </div>
                <p v-if="!waiting.length" class="m-0 text-sm text-gray-500">Nothing to collect: approved shifts show here until you collect them.</p>
                <div
                    v-for="s in waiting"
                    :key="s.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 py-2 text-sm"
                    :data-testid="`waiting-shift-${s.id}`"
                >
                    <span>
                        <b>{{ s.store }}</b> · {{ s.user?.name }} · {{ dayjs(s.business_date).format("MMM D") }}
                        <span class="text-xs text-gray-500">approved<template v-if="s.reviewed_by"> by {{ s.reviewed_by }}</template></span>
                    </span>
                    <span class="flex items-center gap-3">
                        <span class="font-mono tabular-nums">{{ money(s.counted_cash) }}</span>
                        <a-button size="small" type="primary" :data-testid="`collect-shift-${s.id}`" @click="collecting = s">Collect</a-button>
                    </span>
                </div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="text-sm font-semibold text-gray-800">History</div>
            <a-range-picker v-model:value="range" :allow-clear="false" @change="filter" />
        </div>
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <a-table
                :columns="columns"
                :data-source="lines.data"
                :pagination="pagination"
                row-key="id"
                size="middle"
                data-testid="cash-on-hand-lines"
                @change="changePage"
            >
                <template #emptyText>
                    <div class="py-6 text-sm text-gray-500">No Cash on hand lines in these dates.</div>
                </template>
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'date'">
                        <span class="whitespace-nowrap">{{ dayjs(record.at || record.date).format("MMM D, h:mm A") }}</span>
                    </template>
                    <template v-else-if="column.key === 'what'">
                        <div class="font-medium text-gray-800">{{ CASH_ON_HAND_KIND_LABELS[record.kind] ?? record.kind }}</div>
                        <div v-if="record.notes" class="text-xs text-gray-500">{{ record.notes }}</div>
                    </template>
                    <template v-else-if="column.key === 'by'">{{ record.by ?? "—" }}</template>
                    <template v-else-if="column.key === 'amount'">
                        <span class="font-mono tabular-nums" :class="record.direction === 'in' ? 'text-green-700' : 'text-red-700'">
                            {{ record.direction === "in" ? "+" : "−" }} {{ money(record.amount) }}
                        </span>
                    </template>
                    <template v-else-if="column.key === 'balance'">
                        <span class="font-mono tabular-nums">{{ money(record.balance_after) }}</span>
                    </template>
                </template>
            </a-table>
        </div>

        <CollectShiftModal :shift="collecting" :store="collecting?.store" @close="collecting = null" />

        <a-modal
            :visible="!!mode"
            :title="ACTIONS[mode]?.title"
            :ok-text="ACTIONS[mode]?.ok"
            :ok-button-props="{ 'data-testid': 'cash-on-hand-save' }"
            :confirm-loading="form.saving"
            destroy-on-close
            @ok="save"
            @cancel="mode = null"
        >
            <a-form v-if="mode" layout="vertical" class="pt-1">
                <template v-if="mode === 'to_store'">
                    <a-form-item label="Store" required>
                        <a-select
                            v-model:value="form.store"
                            :options="stores.map((s) => ({ value: s.id, label: s.name }))"
                            data-testid="cash-on-hand-store"
                            @change="form.place = 'drawer'"
                        />
                    </a-form-item>
                    <a-form-item label="Into">
                        <a-select v-model:value="form.place" :options="placeOptions" data-testid="cash-on-hand-place" />
                        <div class="mt-1 text-xs text-gray-500">The drawer (e.g. change for tomorrow) or a payment channel (e.g. a bank deposit).</div>
                    </a-form-item>
                </template>
                <a-form-item v-if="mode === 'adjustment'" label="Direction">
                    <a-radio-group v-model:value="form.direction" button-style="solid">
                        <a-radio-button value="in">Add (e.g. cash you already held)</a-radio-button>
                        <a-radio-button value="out">Take off</a-radio-button>
                    </a-radio-group>
                </a-form-item>
                <p v-if="mode === 'owner_use'" class="text-sm text-gray-600">
                    The owner kept this for themselves. It's the one Cash on hand line that counts as an owner withdrawal in the statements.
                </p>
                <a-form-item label="Amount" required :validate-status="overBalance ? 'warning' : ''" :help="overBalance ? `Cash on hand has ${money(balance)}.` : null">
                    <a-input-number v-model:value="form.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="cash-on-hand-amount" />
                </a-form-item>
                <a-form-item :label="mode === 'adjustment' ? 'Why' : 'Note (optional)'" :required="mode === 'adjustment'" class="mb-0">
                    <a-input v-model:value="form.notes" :maxlength="2000" data-testid="cash-on-hand-notes" />
                </a-form-item>
            </a-form>
        </a-modal>
    </WalletLayout>
</template>
