<script setup>
import { computed, reactive, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { message as antMessage } from "ant-design-vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { IconPlus } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";

const props = defineProps({
    movements: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    ledgerBalance: {
        type: Number,
        default: 0,
    },
    railCardTypes: {
        type: Array,
        default: () => [],
    },
    activeLocationId: {
        type: Number,
        default: null,
    },
    isShiftClosed: {
        type: Boolean,
        default: false,
    },
});

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();

const page = usePage();
const todayYmd = new Date().toISOString().slice(0, 10);

function tabQueryFromUrl() {
    const url = page.url;
    if (!url || typeof url !== "string") {
        return {};
    }
    const idx = url.indexOf("?");
    if (idx === -1) {
        return {};
    }
    const params = Object.fromEntries(
        new URLSearchParams(url.slice(idx + 1)),
    );
    const out = {};
    if (params.tab === "ledger" || params.tab === "card-types") {
        out.tab = params.tab;
    }
    if (params.business_date) {
        out.business_date = params.business_date;
    }
    if (params.location_id && /^[0-9]+$/.test(String(params.location_id))) {
        out.location_id = Number(params.location_id);
    } else if (props.activeLocationId) {
        out.location_id = props.activeLocationId;
    }

    return out;
}

const KIND_LABELS = {
    cash_sale_topup: "Cash / float top-up",
    owner_draw: "Owner withdrawal",
    ewallet_transfer_in: "Transfer in (e-wallet)",
    ewallet_transfer_out: "Transfer out (e-wallet)",
    adjustment: "Adjustment",
};

/** Stable backend tokens in `notes` for system-generated cash-control lines. */
const AUTO_CC_NOTE_LABELS = {
    AUTO_CC_OPENING: "Opening cash (saved)",
    AUTO_CC_COUNTED_VARIANCE: "Counted cash variance",
    AUTO_CC_ENDSHIFT_CASHOUT: "End shift cash out",
};

/** @param {string|null|undefined} notes */
function autoLedgerNoteToken(notes) {
    if (notes == null || typeof notes !== "string") {
        return null;
    }
    const t = notes.trim();
    return Object.prototype.hasOwnProperty.call(AUTO_CC_NOTE_LABELS, t)
        ? t
        : null;
}

/** @param {string|null|undefined} notes */
function friendlyAutoLedgerNote(notes) {
    const token = autoLedgerNoteToken(notes);
    return token ? AUTO_CC_NOTE_LABELS[token] : null;
}

/** @param {Record<string, unknown>} record */
function kindCellLabel(record) {
    const fr = friendlyAutoLedgerNote(record.notes);
    if (fr) {
        return fr;
    }
    return KIND_LABELS[record.kind] ?? record.kind;
}

const directionLabels = {
    in: "Cash in",
    out: "Cash out",
};

const filterForm = reactive({
    date_from: props.filters.date_from ?? "",
    date_to: props.filters.date_to ?? "",
    railValue:
        props.filters.rail === "cash_register"
            ? "cash_register"
            : props.filters.payment_card_type_id != null
              ? String(props.filters.payment_card_type_id)
              : "all",
    kind: props.filters.kind ?? "",
});

const railFilterOptions = computed(() => [
    { value: "all", label: "All rails" },
    { value: "cash_register", label: "Cash register (no card rail)" },
    ...props.railCardTypes.map((c) => ({
        value: String(c.id),
        label: c.name,
    })),
]);

const railQueryParams = computed(() => {
    if (filterForm.railValue === "cash_register") {
        return { rail: "cash_register" };
    }
    if (
        filterForm.railValue !== "" &&
        filterForm.railValue !== "all" &&
        /^[0-9]+$/.test(String(filterForm.railValue))
    ) {
        return { payment_card_type_id: Number(filterForm.railValue) };
    }

    return {};
});

function visitWithFilters(overrides = {}) {
    const q = {
        ...tabQueryFromUrl(),
        date_from: filterForm.date_from || undefined,
        date_to: filterForm.date_to || undefined,
        kind: filterForm.kind || undefined,
        page: overrides.page ?? props.movements?.current_page ?? 1,
        ...railQueryParams.value,
        ...overrides,
    };
    router.get(getRoute("payment-card-types.index"), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function applyFilters() {
    visitWithFilters({ page: 1 });
}

const columns = [
    { title: "Date", dataIndex: "movement_date", key: "movement_date" },
    { title: "Kind", key: "kind" },
    { title: "Rail", key: "rail" },
    { title: "Direction", key: "direction" },
    { title: "Amount", key: "amount" },
    { title: "Notes", dataIndex: "notes", key: "notes", ellipsis: true },
    { title: "Recorded by", key: "user" },
];

function rowRail(m) {
    return m.payment_card_type?.name ?? "Cash register";
}

/** Human-readable movement date; handles YYYY-MM-DD without TZ shift. */
function formatMovementDate(raw) {
    if (raw == null || raw === "") {
        return "—";
    }
    try {
        let d;
        const s = String(raw).trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
            const [y, m, day] = s.split("-").map(Number);
            d = new Date(y, m - 1, day);
        } else {
            d = new Date(s);
        }
        if (Number.isNaN(d.getTime())) {
            return s;
        }
        const main = d.toLocaleDateString(undefined, {
            weekday: "short",
            year: "numeric",
            month: "short",
            day: "numeric",
        });
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const rowDay = new Date(d);
        rowDay.setHours(0, 0, 0, 0);
        const diffDays = Math.round((today - rowDay) / 86400000);
        if (diffDays === 0) {
            return `${main} · Today`;
        }
        if (diffDays === 1) {
            return `${main} · Yesterday`;
        }

        return main;
    } catch {
        return String(raw);
    }
}

const modalVisible = ref(false);
const submitting = ref(false);
const modalTitle = ref("Add ledger entry");

const entryForm = reactive({
    direction: "in",
    amount: null,
    kind: "adjustment",
    drawSource: "cash_register",
    payment_card_type_id: null,
    movement_date: new Date().toISOString().slice(0, 10),
    notes: "",
});

const isOwnerDraw = computed(() => entryForm.kind === "owner_draw");

function resetDrawFields() {
    entryForm.drawSource = "cash_register";
    entryForm.payment_card_type_id = null;
}

function openModal(options = {}) {
    if (props.isShiftClosed) {
        antMessage.warning("Shift is closed for this date/location. Reopen to add entries.");
        return;
    }
    const direction = options.direction ?? "in";
    const kind = options.kind ?? "adjustment";
    const title = options.title ?? "Add ledger entry";

    entryForm.direction = direction;
    entryForm.amount = null;
    entryForm.kind = kind;
    resetDrawFields();
    entryForm.movement_date = new Date().toISOString().slice(0, 10);
    entryForm.notes = "";
    modalTitle.value = title;
    modalVisible.value = true;
}

function closeModal() {
    modalVisible.value = false;
}

watch(
    () => entryForm.kind,
    (k) => {
        if (k === "owner_draw") {
            entryForm.direction = "out";
        } else {
            resetDrawFields();
        }
    },
);

watch(
    () => entryForm.drawSource,
    (src) => {
        if (src === "cash_register") {
            entryForm.payment_card_type_id = null;
        }
    },
);

function buildStorePayload() {
    const amt = Number(entryForm.amount);
    const base = {
        direction: entryForm.direction,
        amount: amt,
        kind: entryForm.kind,
        movement_date: entryForm.movement_date,
        notes: entryForm.notes || null,
        location_id: props.activeLocationId,
    };
    if (entryForm.kind === "owner_draw") {
        base.draw_source = entryForm.drawSource;
        base.payment_card_type_id =
            entryForm.drawSource === "card_type"
                ? entryForm.payment_card_type_id || null
                : null;

        return base;
    }
    base.payment_card_type_id = entryForm.payment_card_type_id || null;

    return base;
}

function submitEntry() {
    const amt = Number(entryForm.amount);
    if (!(amt >= 0.01)) {
        antMessage.warning("Enter a valid amount.");

        return;
    }
    if (
        entryForm.kind === "owner_draw" &&
        entryForm.drawSource === "card_type" &&
        !entryForm.payment_card_type_id
    ) {
        antMessage.warning("Choose a terminal / card rail.");

        return;
    }
    submitting.value = true;
    router.post(
        getRoute("wallet-cash-ledger.store"),
        buildStorePayload(),
        {
            preserveScroll: true,
            onSuccess: () => {
                submitting.value = false;
                modalVisible.value = false;
                antMessage.success("Ledger entry saved.");
            },
            onError: () => {
                submitting.value = false;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function onTableChange(tablePagination) {
    visitWithFilters({ page: tablePagination.current });
}

const paginationConfig = computed(() => ({
    current: props.movements.current_page,
    pageSize: props.movements.per_page,
    total: props.movements.total,
    showSizeChanger: false,
}));

watch(
    () => props.filters,
    (f) => {
        filterForm.date_from = f.date_from ?? "";
        filterForm.date_to = f.date_to ?? "";
        filterForm.kind = f.kind ?? "";
        filterForm.railValue =
            f.rail === "cash_register"
                ? "cash_register"
                : f.payment_card_type_id != null
                  ? String(f.payment_card_type_id)
                  : "all";
    },
    { deep: true },
);
</script>

<template>
    <div id="cash-ledger" class="space-y-4">
        <p class="max-w-none text-sm text-gray-600">
            Record cash in and cash out manually (owner draws, float,
            adjustments). Ledger balance is from entries below, not POS sales
            totals.
        </p>

        <div class="grid max-w-none gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm sm:col-span-2 lg:col-span-1"
            >
                <div class="text-xs font-semibold uppercase text-gray-500">
                    Ledger net (manual only)
                </div>
                <div
                    class="mt-1 font-mono text-2xl font-bold tabular-nums"
                    :class="
                        Number(ledgerBalance) >= 0
                            ? 'text-green-700'
                            : 'text-red-700'
                    "
                >
                    {{ formattedTotal(Number(ledgerBalance)) }}
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    Filtered period and rail/kind above.
                </p>
            </div>
            <div
                class="flex flex-wrap gap-4 rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm lg:col-span-2"
            >
                <div class="grid min-w-0 flex-1 grid-cols-2 gap-x-6 gap-y-3">
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-gray-600"
                            >From</label
                        >
                        <input
                            v-model="filterForm.date_from"
                            type="date"
                            class="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                        >
                    </div>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-gray-600"
                            >To</label
                        >
                        <input
                            v-model="filterForm.date_to"
                            type="date"
                            class="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                        >
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label
                            class="mb-1 block text-xs font-medium text-gray-600"
                            >Rail</label
                        >
                        <a-select
                            v-model:value="filterForm.railValue"
                            class="w-full"
                            :options="railFilterOptions"
                            option-filter-prop="label"
                            show-search
                        />
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label
                            class="mb-1 block text-xs font-medium text-gray-600"
                            >Kind</label
                        >
                        <a-select
                            v-model:value="filterForm.kind"
                            allow-clear
                            placeholder="All kinds"
                            class="w-full"
                            :options="[
                                ...Object.entries(KIND_LABELS).map(
                                    ([value, label]) => ({ value, label }),
                                ),
                            ]"
                        />
                    </div>
                </div>
                <div class="flex min-w-[8rem] items-end gap-2">
                    <a-button type="primary" @click="applyFilters">
                        Apply
                    </a-button>
                </div>
            </div>
        </div>

        <ContentLayout title="Ledger movements">
            <template #filters>
                <a-button
                    v-if="hasPermission('wallet-cash-ledger.store')"
                    type="primary"
                    class="flex items-center border border-teal-500 bg-white text-teal-600"
                    :disabled="isShiftClosed"
                    @click="
                        openModal({
                            direction: 'in',
                            kind: 'cash_sale_topup',
                            title: 'Add cash in entry',
                        })
                    "
                >
                    <template #icon>
                        <IconPlus class="h-4 w-4" />
                    </template>
                    Cash in
                </a-button>
                <a-button
                    v-if="hasPermission('wallet-cash-ledger.store')"
                    type="primary"
                    class="flex items-center border border-rose-500 bg-white text-rose-600"
                    :disabled="isShiftClosed"
                    @click="
                        openModal({
                            direction: 'out',
                            kind: 'owner_draw',
                            title: 'Add cash out entry',
                        })
                    "
                >
                    Cash out
                </a-button>
                <a-button
                    v-if="hasPermission('wallet-cash-ledger.store')"
                    :disabled="isShiftClosed"
                    @click="openModal({ title: 'Add ledger entry' })"
                >
                    Other entry
                </a-button>
            </template>
            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="movements.data"
                    :pagination="paginationConfig"
                    :scroll="{ x: 900 }"
                    row-key="id"
                    :locale="{ emptyText: 'No ledger entries yet.' }"
                    @change="onTableChange"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'kind'">
                            {{ kindCellLabel(record) }}
                        </template>
                        <template v-else-if="column.key === 'rail'">
                            {{ rowRail(record) }}
                        </template>
                        <template v-else-if="column.key === 'direction'">
                            {{
                                directionLabels[record.direction] ??
                                record.direction
                            }}
                        </template>
                        <template v-else-if="column.key === 'amount'">
                            <span class="font-medium tabular-nums">
                                {{ formattedTotal(Number(record.amount) || 0) }}
                            </span>
                        </template>
                        <template v-else-if="column.key === 'movement_date'">
                            <span class="text-sm text-gray-900">{{
                                formatMovementDate(record.movement_date)
                            }}</span>
                        </template>
                        <template v-else-if="column.key === 'notes'">
                            <span
                                v-if="friendlyAutoLedgerNote(record.notes)"
                                class="text-gray-800"
                                :title="`System code: ${String(record.notes).trim()}`"
                            >
                                {{ friendlyAutoLedgerNote(record.notes) }}
                            </span>
                            <span v-else>{{
                                record.notes != null && String(record.notes) !== ""
                                    ? record.notes
                                    : "—"
                            }}</span>
                        </template>
                        <template v-else-if="column.key === 'user'">
                            <span title="User who saved this ledger line.">{{
                                record.user?.name ?? "-"
                            }}</span>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="modalVisible"
            :title="modalTitle"
            :confirm-loading="submitting"
            ok-text="Save"
            destroy-on-close
            @ok="submitEntry"
            @cancel="closeModal"
        >
            <div class="flex flex-col gap-4 pt-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Cash flow direction</label
                    >
                    <a-radio-group v-model:value="entryForm.direction">
                        <a-radio-button
                            value="in"
                            :disabled="isOwnerDraw"
                        >
                            Cash in
                        </a-radio-button>
                        <a-radio-button value="out">Cash out</a-radio-button>
                    </a-radio-group>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Kind</label
                    >
                    <a-select
                        v-model:value="entryForm.kind"
                        class="w-full"
                        :options="
                            Object.entries(KIND_LABELS).map(([value, label]) => ({
                                value,
                                label,
                            }))
                        "
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Amount</label
                    >
                    <a-input-number
                        v-model:value="entryForm.amount"
                        :min="0.01"
                        :step="0.01"
                        class="w-full"
                        placeholder="0.00"
                    />
                </div>
                <div v-if="isOwnerDraw">
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Withdraw from</label
                    >
                    <a-radio-group v-model:value="entryForm.drawSource">
                        <a-radio-button value="cash_register">
                            Cash register
                        </a-radio-button>
                        <a-radio-button value="card_type">
                            Terminal / card rail
                        </a-radio-button>
                    </a-radio-group>
                </div>
                <div v-if="isOwnerDraw && entryForm.drawSource === 'card_type'">
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Card rail</label
                    >
                    <a-select
                        v-model:value="entryForm.payment_card_type_id"
                        placeholder="Choose rail"
                        class="w-full"
                        :options="
                            railCardTypes.map((c) => ({
                                value: c.id,
                                label: c.name,
                            }))
                        "
                    />
                </div>
                <div v-if="!isOwnerDraw">
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Rail (optional)</label
                    >
                    <a-select
                        v-model:value="entryForm.payment_card_type_id"
                        allow-clear
                        placeholder="Cash register — no terminal rail"
                        class="w-full"
                        :options="
                            railCardTypes.map((c) => ({
                                value: c.id,
                                label: c.name,
                            }))
                        "
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Movement date</label
                    >
                    <input
                        v-model="entryForm.movement_date"
                        type="date"
                        :max="todayYmd"
                        class="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                    >
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700"
                        >Notes</label
                    >
                    <a-textarea
                        v-model:value="entryForm.notes"
                        :rows="2"
                        placeholder="Optional memo"
                    />
                </div>
            </div>
        </a-modal>
    </div>
</template>
