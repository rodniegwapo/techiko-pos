<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import IconTooltip from "@/Components/buttons/IconTooltip.vue";
import { IconCheck, IconRefresh } from "@tabler/icons-vue";

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
    <span class="inline-flex justify-end gap-2">
        <IconTooltip v-if="showApprove" name="Approve count" hover="hover:bg-green-600" :data-testid="`count-approve-${shift.id}`" @click="open('approve')">
            <IconCheck size="18" class="mx-auto" />
        </IconTooltip>
        <IconTooltip v-if="showRecount" name="Send back for a recount" hover="hover:bg-amber-500" :data-testid="`count-reopen-${shift.id}`" @click="open('recount')">
            <IconRefresh size="18" class="mx-auto" />
        </IconTooltip>

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
                {{ shift.user?.name }}'s shift<template v-if="shift.store"> at <b data-testid="approve-store">{{ shift.store }}</b></template> on {{ dayjs(shift.business_date).format("MMM D") }}: counted
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
                {{ shift.user?.name }}'s shift<template v-if="shift.store"> at <b>{{ shift.store }}</b></template> opens again so they can count the drawer and end it once more.
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
