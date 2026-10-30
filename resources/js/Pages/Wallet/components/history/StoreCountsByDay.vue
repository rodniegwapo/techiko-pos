<script setup>
import { computed, reactive } from "vue";
import dayjs from "dayjs";
import { useHelpers } from "@/Composables/useHelpers";
import { shortOver, TONE_CLASSES, COUNT_KIND_LABELS } from "../walletLabels";
import { useWalletQuery } from "../../composables/useWalletQuery";

/**
 * Store counts, on Shifts & counts, for checking back: each count and opening change on a day, a
 * check of the drawer since its last count (across days), and the days the store ended.
 */
const props = defineProps({
    cashControl: { type: Object, required: true },
    closedShifts: { type: Object, required: true },
    activeLocation: { type: Object, default: null },
});

const { formattedTotal } = useHelpers();
const { visit, todayYmd } = useWalletQuery(() => props.activeLocation);

const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v)));
const when = (iso) => (iso ? dayjs(iso).format("MMM D, YYYY · h:mm A") : "");
/** "Short ₱50.00", "Over ₱60.00", "Balanced", or — when not counted. */
const resultText = (variance) => {
    const r = shortOver(variance);
    if (!r) return "—";
    return r.amount ? `${r.label} ${money(r.amount)}` : r.label;
};
const isFuture = (current) => !!current && current.format("YYYY-MM-DD") > todayYmd;

const counts = computed(() => props.cashControl.count_submission_history ?? []);
const openings = computed(() => props.cashControl.opening_audit_history ?? []);
const sinceLast = computed(() => shortOver(props.cashControl.bridge_variance));
// The day's numbers are off but the check since the last count matches: the opening was probably wrong.
const openingLooksWrong = computed(() => {
    const c = props.cashControl;
    return c.variance != null && c.bridge_variance != null
        && Math.abs(Number(c.bridge_variance)) <= 0.01 && Math.abs(Number(c.variance)) > 0.01;
});

const shiftFilters = reactive({
    date_from: props.closedShifts.filters?.date_from ?? "",
    date_to: props.closedShifts.filters?.date_to ?? "",
});
const shiftRows = computed(() => props.closedShifts.rows ?? []);
const showShifts = () => visit("register-shifts.index", { view: "store", ...shiftFilters }, {}, { preserveState: true });
</script>

<template>
    <div data-testid="store-counts">
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-600">Day</span>
            <a-date-picker
                :value="cashControl.business_date"
                value-format="YYYY-MM-DD"
                :allow-clear="false"
                :disabled-date="isFuture"
                data-testid="history-date"
                @change="(v) => v && visit('register-shifts.index', { view: 'store', business_date: v })"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-testid="history-counts">
                <h3 class="mb-2 text-sm font-semibold text-gray-800">Counts on {{ cashControl.business_date }}</h3>
                <p v-if="!counts.length" class="m-0 text-sm text-gray-500">No counts on this day.</p>
                <ul v-else class="m-0 list-none divide-y divide-gray-100 p-0 text-sm">
                    <li v-for="c in counts" :key="c.id" class="py-2">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium">
                                {{ money(c.counted_cash) }} counted
                                <a-tag v-if="COUNT_KIND_LABELS[c.kind]" class="ml-1" :color="c.kind === 'end_of_day' ? 'blue' : undefined" :data-testid="`count-kind-${c.kind}`">
                                    {{ COUNT_KIND_LABELS[c.kind] }}
                                </a-tag>
                            </span>
                            <span v-if="shortOver(c.variance_snapshot)" :class="TONE_CLASSES[shortOver(c.variance_snapshot).tone]">
                                {{ resultText(c.variance_snapshot) }}
                            </span>
                        </div>
                        <div class="text-xs text-gray-500">
                            Expected then {{ money(c.expected_cash_snapshot) }}<template v-if="c.counted_by_user"> · {{ c.counted_by_user.name }}</template> · {{ when(c.counted_at) }}
                        </div>
                        <div v-if="c.notes" class="text-xs text-gray-500">{{ c.notes }}</div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-testid="history-openings">
                <h3 class="mb-2 text-sm font-semibold text-gray-800">Opening changes on {{ cashControl.business_date }}</h3>
                <p v-if="!openings.length" class="m-0 text-sm text-gray-500">The opening cash wasn't changed on this day.</p>
                <ul v-else class="m-0 list-none divide-y divide-gray-100 p-0 text-sm">
                    <li v-for="a in openings" :key="a.id" class="py-2">
                        <div>
                            <template v-if="a.old_opening_cash != null">{{ money(a.old_opening_cash) }} → </template>{{ money(a.new_opening_cash) }}
                        </div>
                        <div class="text-xs text-gray-500">
                            <template v-if="a.changed_by_user">{{ a.changed_by_user.name }} · </template>{{ when(a.changed_at) }}
                        </div>
                        <div v-if="a.reason" class="text-xs text-gray-500">{{ a.reason }}</div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2" data-testid="history-since-last-count">
                <h3 class="mb-1 text-sm font-semibold text-gray-800">Since the last count</h3>
                <template v-if="cashControl.bridge_anchor_business_date">
                    <p class="m-0 text-sm text-gray-600">
                        Counted {{ money(cashControl.bridge_anchor_counted_cash) }} on {{ cashControl.bridge_anchor_business_date }}.
                        Adding every day's cash sales and cash in and out since then, the drawer should hold
                        <span class="font-semibold">{{ money(cashControl.bridge_expected_cash) }}</span> on {{ cashControl.business_date }}.
                    </p>
                    <p v-if="sinceLast" class="mb-0 mt-1 text-sm font-medium" :class="TONE_CLASSES[sinceLast.tone]">
                        Against today's count: {{ resultText(cashControl.bridge_variance) }}
                    </p>
                    <p v-if="cashControl.bridge_span_warning" class="mb-0 mt-1 text-xs text-amber-800">That count is over a year ago; check the figures carefully.</p>
                    <p v-if="openingLooksWrong" class="mb-0 mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-2 text-xs text-amber-900">
                        This matches the count, but the day's Expected doesn't. The opening cash for {{ cashControl.business_date }} is probably wrong:
                        fix it with Set opening on Today's drawer.
                    </p>
                </template>
                <p v-else class="m-0 text-sm text-gray-500">This store has no earlier count yet.</p>
            </section>
        </div>

        <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-testid="history-shifts">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <h3 class="m-0 text-sm font-semibold text-gray-800">Days the store ended</h3>
                <div class="flex flex-wrap items-end gap-2">
                    <a-date-picker v-model:value="shiftFilters.date_from" value-format="YYYY-MM-DD" placeholder="From" />
                    <a-date-picker v-model:value="shiftFilters.date_to" value-format="YYYY-MM-DD" placeholder="To" />
                    <a-button @click="showShifts">Show</a-button>
                </div>
            </div>
            <p v-if="!shiftRows.length" class="m-0 text-sm text-gray-500">No days ended in this period.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3 text-right">Expected</th>
                            <th class="py-2 pr-3 text-right">Counted</th>
                            <th class="py-2 pr-3 text-right">Short / Over</th>
                            <th class="py-2 pr-3 text-right">Taken out</th>
                            <th class="py-2">Ended by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in shiftRows" :key="r.id" class="border-t border-gray-100">
                            <td class="py-2 pr-3">{{ r.business_date }}</td>
                            <td class="py-2 pr-3 text-right font-mono">{{ money(r.expected_cash) }}</td>
                            <td class="py-2 pr-3 text-right font-mono">{{ money(r.counted_cash) }}</td>
                            <td class="py-2 pr-3 text-right" :class="shortOver(r.variance) ? TONE_CLASSES[shortOver(r.variance).tone] : ''">
                                {{ resultText(r.variance) }}
                            </td>
                            <td class="py-2 pr-3 text-right font-mono">{{ money(r.cash_out) }}</td>
                            <td class="py-2">{{ r.closed_by || "—" }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
