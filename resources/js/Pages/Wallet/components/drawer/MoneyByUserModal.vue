<script setup>
import { ref, watch } from "vue";
import axios from "axios";
import dayjs from "dayjs";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { SHIFT_STATUS } from "../walletLabels";
import { firstValidationMessage } from "../../composables/useCashDrawerActions";

/**
 * View details: who took in what on a day at this store, by where the money went (the cash
 * drawer, GCash, a bank, a card terminal, credit), with each person's shifts and what should be in
 * their drawer.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    locationId: { type: [Number, String], default: null },
    businessDate: { type: String, required: true },
});
const emit = defineEmits(["close"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const money = (v) => formattedTotal(Number(v) || 0);
const time = (at) => (at ? dayjs(at).format("h:mm A") : "—");

const loading = ref(false);
const error = ref(null);
const users = ref([]);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        loading.value = true;
        error.value = null;
        try {
            const { data } = await axios.get(getRoute("register-shifts.money-by-user"), {
                params: { location_id: props.locationId, business_date: props.businessDate },
            });
            users.value = data.users;
        } catch (e) {
            error.value = firstValidationMessage(e) || "Could not load who holds what.";
        } finally {
            loading.value = false;
        }
    },
);
</script>

<template>
    <a-modal :visible="open" title="Money per user" :width="720" :footer="null" @cancel="emit('close')">
        <p class="mb-3 text-sm text-gray-600">
            {{ dayjs(businessDate).format("ddd, MMM D, YYYY") }}: each person's sales by where the money went, and their shifts.
        </p>
        <a-spin :spinning="loading">
            <a-alert v-if="error" type="error" :message="error" show-icon class="mb-3" />
            <div v-else-if="!loading && !users.length" class="py-6 text-center text-sm text-gray-500">
                No sales or shifts on this day yet.
            </div>
            <div v-else class="flex flex-col gap-3" data-testid="money-by-user">
                <div
                    v-for="row in users"
                    :key="row.user.id"
                    class="rounded-lg border border-gray-200 p-3"
                    :data-testid="`money-user-${row.user.id}`"
                >
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold text-gray-800">{{ row.user.name }}</span>
                        <span class="font-mono text-sm font-semibold tabular-nums">Total {{ money(row.total) }}</span>
                    </div>
                    <div v-if="row.places.length" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div v-for="p in row.places" :key="p.key" class="rounded bg-gray-50 px-2 py-1.5">
                            <div class="truncate text-xs text-gray-500">{{ p.label }}</div>
                            <div class="font-mono text-sm tabular-nums">{{ money(p.amount) }}</div>
                        </div>
                    </div>
                    <p v-else class="m-0 text-xs text-gray-500">No sales yet.</p>
                    <div v-for="s in row.shifts" :key="s.id" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                        <a-tag :color="SHIFT_STATUS[s.status]?.color" class="m-0">{{ SHIFT_STATUS[s.status]?.label ?? s.status }}</a-tag>
                        <span>{{ time(s.opened_at) }} – {{ s.closed_at ? time(s.closed_at) : "now" }}</span>
                        <span>· {{ s.status === "open" ? "In their drawer now" : "Expected" }} {{ money(s.expected_cash) }}</span>
                        <span v-if="s.counted_cash !== null && s.counted_cash !== undefined">· Counted {{ money(s.counted_cash) }}</span>
                    </div>
                </div>
            </div>
        </a-spin>
    </a-modal>
</template>
