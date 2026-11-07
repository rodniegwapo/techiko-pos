<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import IconTooltip from "@/Components/buttons/IconTooltip.vue";
import { IconCheck, IconCalculator, IconRefresh } from "@tabler/icons-vue";

/**
 * A manager's actions on an ended shift's count: Approve it (with an optional note of what was
 * found), Recount it (a new count with a reason, any day; the shift stays ended and the old count
 * stays in the history), or Reopen it (only today's: the cashier counts again and ends it once more).
 * The server checks the rest: not your own count, a shift the cashier has open now, an ended store
 * day. Used on Shifts & counts and Today's drawer.
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
const { hasPermission } = usePermissionsV2();
// Reopening is today's only (an earlier day's shift would take today's sales); a recount is any day.
const showReopen = computed(() => showApprove.value && dayjs(props.shift.business_date).isSame(dayjs(), "day"));
const showRecount = computed(() => ended.value && hasPermission("register-shifts.recount"));

const modal = ref(null);
const note = ref("");
const reason = ref("");
const busy = ref(false);
const counted = ref(null);
const reasonOk = computed(() => reason.value.trim().length >= 3);
const recountOk = computed(() => reasonOk.value && counted.value !== null && counted.value !== "" && Number(counted.value) >= 0);

function open(which) {
    note.value = "";
    reason.value = "";
    counted.value = null;
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
    if (!recountOk.value) return;
    post("register-shifts.recount", { counted_cash: Number(counted.value), reason: reason.value.trim() }, "Recount saved.", "Could not save the recount.");
}
function reopen() {
    if (!reasonOk.value) return;
    post("register-shifts.reopen", { reason: reason.value.trim() }, "Shift reopened for a recount.", "Could not reopen the shift.");
}
</script>

<template>
    <span class="inline-flex justify-end gap-2">
        <IconTooltip v-if="showApprove" name="Approve count" hover="hover:bg-green-600" :data-testid="`count-approve-${shift.id}`" @click="open('approve')">
            <IconCheck size="18" class="mx-auto" />
        </IconTooltip>
        <IconTooltip v-if="showRecount" name="Recount" hover="hover:bg-blue-600" :data-testid="`count-recount-${shift.id}`" @click="open('recount')">
            <IconCalculator size="18" class="mx-auto" />
        </IconTooltip>
        <IconTooltip v-if="showReopen" name="Reopen for the cashier to count again" hover="hover:bg-amber-500" :data-testid="`count-reopen-${shift.id}`" @click="open('reopen')">
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
            title="Recount this shift"
            ok-text="Save recount"
            :ok-button-props="{ disabled: !recountOk, 'data-testid': 'recount-confirm' }"
            :confirm-loading="busy"
            @ok="recount"
            @cancel="modal = null"
        >
            <p class="text-sm text-gray-600">
                {{ shift.user?.name }}'s shift<template v-if="shift.store"> at <b>{{ shift.store }}</b></template> on {{ dayjs(shift.business_date).format("MMM D") }}:
                counted {{ money(shift.counted_cash) }} against {{ money(shift.expected_cash) }} expected. Enter what the drawer really held.
                The shift stays ended, and this count stays in its history.
            </p>
            <a-input-number
                v-model:value="counted"
                :min="0"
                :precision="2"
                prefix="₱"
                placeholder="Counted cash"
                class="!w-full mb-2"
                data-testid="recount-amount"
            />
            <a-input v-model:value="reason" placeholder="Reason, e.g. counted wrong" :maxlength="255" data-testid="recount-reason" @press-enter="recount" />
        </a-modal>

        <a-modal
            :visible="modal === 'reopen'"
            title="Reopen for a recount"
            ok-text="Reopen shift"
            :ok-button-props="{ disabled: !reasonOk, 'data-testid': 'reopen-confirm' }"
            :confirm-loading="busy"
            @ok="reopen"
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
                @press-enter="reopen"
            />
        </a-modal>
    </span>
</template>
