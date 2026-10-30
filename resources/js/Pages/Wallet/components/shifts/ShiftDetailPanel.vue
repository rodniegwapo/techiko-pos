<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { shortOver, TONE_CLASSES, SHIFT_STATUS, COUNT_KIND_LABELS, movementLabel } from "../walletLabels";
import { firstValidationMessage } from "../../composables/useCashDrawerActions";
import ShiftReviewButtons from "./ShiftReviewButtons.vue";

/**
 * One shift in full, in a side panel: how its Expected is reached (opening + cash sales + cash in −
 * cash out), what was counted and how far off it was, and a timeline of everything that happened
 * on it (opened, cash lines, spot checks, counts, reopens, ended, approved). Approve or send it
 * back for a recount from the bottom. Used on Shifts & counts and Today's drawer.
 */
const props = defineProps({
    /** The shift row to show (null: closed). */
    shift: { type: Object, default: null },
    canApprove: { type: Boolean, default: false },
    /** Short or over: for managers and whoever approves counts. */
    showResult: { type: Boolean, default: true },
    currentUserId: { type: Number, default: null },
});
const emit = defineEmits(["close"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v) || 0));
const at = (iso) => (iso ? dayjs(iso).format("MMM D, h:mm A") : "");

const detail = ref(null);
const loading = ref(false);

watch(
    () => props.shift?.id,
    async (id) => {
        detail.value = null;
        if (!id) return;
        loading.value = true;
        try {
            const { data } = await axios.get(getRoute("register-shifts.show", { registerShift: id }));
            detail.value = data;
        } catch (e) {
            notification.error({ message: firstValidationMessage(e) || "Could not load the shift." });
            emit("close");
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

// The row that opened the panel until the full shift loads, so the header shows at once.
const row = computed(() => detail.value?.shift ?? props.shift ?? {});
const figures = computed(() => detail.value?.figures ?? null);
const isOpen = computed(() => row.value.status === "open");
// Ended: what it was counted against. Open: what should be there now.
const expected = computed(() => (isOpen.value ? figures.value?.expected_cash : row.value.expected_cash));
const result = computed(() => (!isOpen.value && props.showResult ? shortOver(row.value.variance) : null));

const timeline = computed(() => {
    const d = detail.value;
    if (!d) return [];
    const s = d.shift;
    const events = [
        { at: s.opened_at, title: `Shift started with ${money(s.opening_cash)}`, by: s.user?.name, tone: "gray" },
        ...(d.cash_lines ?? []).map((l) => ({
            at: l.created_at,
            title: `${movementLabel(l)}: ${l.direction === "in" ? "+" : "−"} ${money(l.amount)}`,
            note: l.notes,
            tone: l.direction === "in" ? "green" : "red",
        })),
        ...(d.counts ?? []).map((c) => ({
            at: c.counted_at,
            title: `${COUNT_KIND_LABELS[c.kind] ?? "Count"}: counted ${money(c.counted_cash)} of ${money(c.expected_cash)}`,
            by: c.counted_by,
            note: c.notes,
            result: props.showResult ? shortOver(c.variance) : null,
            tone: "blue",
        })),
        ...(d.reopens ?? []).map((r) => ({ at: r.at, title: "Reopened", by: r.by, note: r.reason, tone: "orange" })),
        ...(s.closed_at ? [{ at: s.closed_at, title: "Shift ended", tone: "gray" }] : []),
        ...(s.reviewed_at ? [{ at: s.reviewed_at, title: "Count approved", by: s.reviewed_by, note: s.review_note, tone: "green" }] : []),
    ];
    return events.filter((e) => e.at).sort((a, b) => (a.at < b.at ? -1 : a.at > b.at ? 1 : 0));
});
const DOT = { gray: "bg-gray-400", green: "bg-green-600", red: "bg-red-600", blue: "bg-blue-600", orange: "bg-amber-500" };
const showSales = ref(false);
</script>

<template>
    <a-drawer
        :visible="!!shift"
        :width="520"
        :title="row.user ? `${row.user.name}'s shift` : 'Shift'"
        placement="right"
        destroy-on-close
        @close="emit('close')"
    >
        <a-spin :spinning="loading">
            <div class="flex flex-col gap-5" data-testid="count-detail">
                <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600">
                    <a-tag :color="SHIFT_STATUS[row.status]?.color" class="m-0">
                        {{ row.status === "open" ? "On shift" : SHIFT_STATUS[row.status]?.label ?? row.status }}
                    </a-tag>
                    <span v-if="row.store" class="font-medium text-gray-800" data-testid="shift-panel-store">{{ row.store }}</span>
                    <span>{{ dayjs(row.business_date).format("ddd, MMM D") }}</span>
                    <span>· {{ row.opened_at ? dayjs(row.opened_at).format("h:mm A") : "" }} – {{ row.closed_at ? dayjs(row.closed_at).format("h:mm A") : "now" }}</span>
                </div>

                <!-- How Expected is reached, and what was counted -->
                <section v-if="figures" class="rounded-lg border border-gray-200" data-testid="shift-sheet">
                    <div class="border-b border-gray-100 px-4 py-2 text-xs font-semibold uppercase text-gray-500">What should be in the drawer</div>
                    <div class="flex flex-col gap-1 px-4 py-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-600">Opening</span><span class="font-mono tabular-nums">{{ money(figures.opening_cash) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">+ Cash sales</span><span class="font-mono tabular-nums text-green-700">+ {{ money(figures.paid_cash_sales) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">+ Cash in</span><span class="font-mono tabular-nums text-green-700">+ {{ money(figures.manual_in) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">− Cash out</span><span class="font-mono tabular-nums text-red-700">− {{ money(figures.manual_out) }}</span></div>
                        <div class="mt-1 flex justify-between border-t border-gray-100 pt-2 font-semibold">
                            <span>= Expected</span><span class="font-mono tabular-nums" data-testid="shift-sheet-expected">{{ money(expected) }}</span>
                        </div>
                        <div v-if="!isOpen" class="flex justify-between font-semibold">
                            <span>Counted</span><span class="font-mono tabular-nums">{{ money(row.counted_cash) }}</span>
                        </div>
                    </div>
                    <div
                        v-if="result"
                        class="flex items-center justify-between border-t px-4 py-3 text-sm font-semibold"
                        :class="{ ok: 'border-green-100 bg-green-50', short: 'border-red-100 bg-red-50', over: 'border-amber-100 bg-amber-50' }[result.tone]"
                        data-testid="shift-sheet-result"
                    >
                        <span :class="TONE_CLASSES[result.tone]">{{ result.label }}</span>
                        <span class="font-mono tabular-nums" :class="TONE_CLASSES[result.tone]">{{ result.amount ? money(result.amount) : "" }}</span>
                    </div>
                </section>

                <!-- Everything that happened on the shift, in order -->
                <section>
                    <div class="mb-2 text-xs font-semibold uppercase text-gray-500">Counts &amp; events</div>
                    <p v-if="detail && !(detail.counts ?? []).length" class="mb-2 mt-0 text-xs text-gray-500">Not counted yet.</p>
                    <ol class="m-0 list-none p-0" data-testid="shift-timeline">
                        <li v-for="(e, i) in timeline" :key="i" class="relative flex gap-3 pb-3 text-sm">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="DOT[e.tone]" />
                            <div class="min-w-0 flex-1">
                                <div class="flex justify-between gap-2">
                                    <span class="text-gray-800">{{ e.title }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ at(e.at) }}</span>
                                </div>
                                <div v-if="e.by || e.note" class="text-xs text-gray-500">
                                    <template v-if="e.by">{{ e.by }}</template><template v-if="e.by && e.note"> · </template><template v-if="e.note">{{ e.note }}</template>
                                </div>
                                <div v-if="e.result" class="text-xs" :class="TONE_CLASSES[e.result.tone]">
                                    {{ e.result.amount ? `${e.result.label} ${money(e.result.amount)}` : e.result.label }}
                                </div>
                            </div>
                        </li>
                    </ol>
                </section>

                <section v-if="detail">
                    <a-button type="link" class="h-auto !px-0 text-sm" data-testid="shift-sales-toggle" @click="showSales = !showSales">
                        {{ showSales ? "Hide" : "Show" }} sales ({{ detail.sales.length }})
                    </a-button>
                    <div v-if="showSales" class="mt-2 max-h-60 overflow-y-auto">
                        <p v-if="!detail.sales.length" class="m-0 text-xs text-gray-500">None.</p>
                        <div v-for="s in detail.sales" :key="s.id" class="flex justify-between border-b border-gray-100 py-1 text-sm">
                            <span>{{ s.at ? dayjs(s.at).format("h:mm A") : "" }} · {{ s.invoice_number }} · {{ s.channel || s.method }}<template v-if="s.status !== 'paid'"> · {{ s.status }}</template></span>
                            <span class="font-mono tabular-nums">{{ money(s.total) }}</span>
                        </div>
                    </div>
                </section>
            </div>
        </a-spin>

        <template #footer>
            <div class="flex justify-end">
                <ShiftReviewButtons v-if="detail" :shift="row" :can-approve="canApprove" :current-user-id="currentUserId" />
            </div>
        </template>
    </a-drawer>
</template>
