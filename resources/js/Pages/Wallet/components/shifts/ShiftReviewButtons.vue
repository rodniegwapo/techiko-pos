<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * A manager's actions on an ended shift's count: Approve it (with an optional note of what was
 * found), or send it back for a Recount (with a reason; only today's, the cashier counts again and
 * the old count stays in the history). The server checks the rest: not your own count, a shift the
 * cashier has open now, an ended store day. Used on Shifts & counts and Today's drawer.
 */
const props = defineProps({
    /** The shift row: { id, user, business_date, status, counted_cash, expected_cash }. */
    shift: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
    currentUserId: { type: Number, default: null },
    /** One above the other, for a narrow column. */
    stacked: { type: Boolean, default: false },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v) || 0));

const ended = computed(() => ["closed", "needs_review"].includes(props.shift.status));
const showApprove = computed(() => props.canApprove && ended.value);
const showRecount = computed(() => showApprove.value && dayjs(props.shift.business_date).isSame(dayjs(), "day"));

const modal = ref(null);
const note = ref("");
const reason = ref("");
const busy = ref(false);
const reasonOk = computed(() => reason.value.trim().length >= 3);

function open(which) {
    note.value = "";
    reason.value = "";
    modal.value = which;
}

function post(route, body, success, failure) {
    busy.value = true;
    router.post(getRoute(route, { registerShift: props.shift.id }), body, {
        preserveScroll: true,
        onSuccess: () => {
            notification.success({ message: success });
            modal.value = null;
        },
        onError: (errors) => notification.error({ message: Object.values(errors || {})[0] || failure }),
        onFinish: () => (busy.value = false),
    });
}

const approve = () => post("register-shifts.approve", { note: note.value.trim() || null }, "Count approved.", "Could not approve.");
function recount() {
    if (!reasonOk.value) return;
    post("register-shifts.reopen", { reason: reason.value.trim() }, "Shift reopened for a recount.", "Could not reopen the shift.");
}
</script>

<template>
    <span class="inline-flex justify-end gap-2" :class="{ 'flex-col items-stretch gap-1': stacked }">
        <a-button v-if="showApprove" size="small" type="primary" :data-testid="`count-approve-${shift.id}`" @click="open('approve')">
            Approve
        </a-button>
        <a-button v-if="showRecount" size="small" :data-testid="`count-reopen-${shift.id}`" @click="open('recount')">
            Recount
        </a-button>

        <a-modal
            :visible="modal === 'approve'"
            title="Approve this count"
            ok-text="Approve"
            :ok-button-props="{ 'data-testid': 'approve-confirm' }"
            :confirm-loading="busy"
            @ok="approve"
            @cancel="modal = null"
        >
            <p class="text-sm text-gray-600">
                {{ shift.user?.name }}'s shift on {{ dayjs(shift.business_date).format("MMM D") }}: counted
                {{ money(shift.counted_cash) }} against {{ money(shift.expected_cash) }} expected.
            </p>
            <p v-if="shift.user?.id === currentUserId" class="text-xs text-amber-700">This is your own shift.</p>
            <a-input v-model:value="note" placeholder="What you found (optional)" :maxlength="255" data-testid="approve-note" />
        </a-modal>

        <a-modal
            :visible="modal === 'recount'"
            title="Reopen for a recount"
            ok-text="Reopen shift"
            :ok-button-props="{ disabled: !reasonOk, 'data-testid': 'reopen-confirm' }"
            :confirm-loading="busy"
            @ok="recount"
            @cancel="modal = null"
        >
            <p class="text-sm text-gray-600">
                {{ shift.user?.name }}'s shift opens again so they can count the drawer and end it once more.
                This count ({{ money(shift.counted_cash) }} against {{ money(shift.expected_cash) }} expected)
                stays in the shift's history.
            </p>
            <a-input
                v-model:value="reason"
                placeholder="Reason, e.g. counted wrong"
                :maxlength="255"
                data-testid="reopen-reason"
                @press-enter="recount"
            />
        </a-modal>
    </span>
</template>
