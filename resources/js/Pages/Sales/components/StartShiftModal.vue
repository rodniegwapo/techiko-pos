<script setup>
import { ref, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { numberKeysOnly, numberPasteOnly } from "@/Composables/numericInput";
import ClockAlongField from "./ClockAlongField.vue";

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const page = usePage();

const props = defineProps({
    visible: { type: Boolean, default: false },
});

const emit = defineEmits(["update:visible", "started"]);

// Each cashier starts their own shift; others already on shift make no difference.
const canStartShift = computed(() => !!page.props.shiftStatus?.can_start);
const reviewPendingSince = computed(() => {
    const at = page.props.shiftStatus?.review_pending_since;
    return at ? new Date(at).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : null;
});
const mayStartAtAll = computed(() => hasPermission("register-shifts.start"));

const businessDate = computed(
    () =>
        page.props.shiftStatus?.business_date ||
        new Date().toISOString().slice(0, 10),
);
// The store the shift status was checked for, so the banner and the button never act on different stores.
const locationId = computed(
    () => page.props.shiftStatus?.location_id ?? page.props.currentLocation?.id ?? null,
);

const openingCash = ref(Number(page.props.shiftStatus?.suggested_opening || 0));
const reason = ref("");
const saving = ref(false);

// "Clock me in too": sent with the opening, checked before anything is saved.
const clock = ref({ enabled: false, pin: "" });
const clockError = ref("");
const clockPinMissing = computed(() => clock.value.enabled && !clock.value.pin);

function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return err?.response?.data?.message || err?.message || "Could not open your drawer.";
}

function close() {
    emit("update:visible", false);
}

// Today's shift was already ended: starting it again would be refused, so offer Reopen instead.
const isClosed = computed(() => !!page.props.shiftStatus?.is_closed);
const canReopen = computed(
    () =>
        !!page.props.shiftStatus?.can_reopen,
);
const closedByName = computed(() => page.props.shiftStatus?.closed_by_name || null);
const closedAt = computed(() => {
    const at = page.props.shiftStatus?.closed_at;
    return at ? new Date(at).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : null;
});

const reopenReason = ref("");
const reasonOk = computed(() => reopenReason.value.trim().length >= 3);

function reopen() {
    if (!reasonOk.value) return;
    saving.value = true;
    router.post(
        getRoute("wallet-cash-ledger.reopen-shift"),
        { location_id: locationId.value, business_date: businessDate.value, reason: reopenReason.value.trim() },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                reopenReason.value = "";
                notification.success({ message: "Day reopened." });
                emit("update:visible", false);
                emit("started");
            },
            onError: (errors) =>
                notification.error({ message: Object.values(errors || {})[0] || "Could not reopen the shift." }),
            onFinish: () => (saving.value = false),
        },
    );
}

async function submit() {
    if (!locationId.value) {
        notification.error({ message: "No active location selected." });
        return;
    }
    saving.value = true;
    try {
        clockError.value = "";
        const clockingIn = clock.value.enabled && !!clock.value.pin;
        await axios.post(getRoute("register-shifts.start"), {
            location_id: locationId.value,
            opening_cash: Number(openingCash.value || 0),
            notes: reason.value || null,
            clock_in_pin: clockingIn ? clock.value.pin : null,
        });
        notification.success({ message: clockingIn ? "Drawer opened. You're clocked in." : "Drawer opened." });
        clock.value = { enabled: false, pin: "" };
        openingCash.value = 0;
        reason.value = "";
        emit("update:visible", false);
        emit("started");
    } catch (e) {
        // A wrong PIN: nothing was saved; say so under the PIN and keep the dialog open.
        const pinError = e?.response?.data?.errors?.clock_in_pin?.[0];
        if (pinError) {
            clockError.value = pinError;
            clock.value = { ...clock.value, pin: "" };
            return;
        }
        notification.error({ message: firstValidationMessage(e) });
        // Refused for the shift's state (e.g. started in another tab): this page's status is stale, refresh it.
        if (e?.response?.data?.errors?.shift) {
            emit("update:visible", false);
            router.reload({ only: ["shiftStatus"] });
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <a-modal
        :visible="visible"
        :title="isClosed ? 'The store\'s day was ended' : 'Open my drawer'"
        width="420px"
        :mask-closable="false"
        :footer="null"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <div v-if="isClosed" class="space-y-4 py-2" data-testid="shift-closed">
            <p class="text-sm text-gray-600">
                A manager ended this store's day<template v-if="closedAt"> at {{ closedAt }}</template>,
                so no shift can start. Payments stay blocked until the day is reopened.
            </p>
            <p v-if="!canReopen" class="text-sm text-gray-600">
                Ask a manager or admin to reopen it.
            </p>
            <div v-else class="flex flex-col gap-1">
                <span class="text-sm text-gray-700">Reason</span>
                <a-input
                    v-model:value="reopenReason"
                    placeholder="e.g. counted wrong"
                    :maxlength="255"
                    data-testid="shift-reopen-reason"
                    @press-enter="reopen"
                />
                <span class="text-xs text-gray-500"><template v-if="closedByName">{{ closedByName }} ended the day. </template>Your name, the time and the reason are kept in the log.</span>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <a-button @click="close">Close</a-button>
                <a-button v-if="canReopen" type="primary" :loading="saving" :disabled="!reasonOk" data-testid="shift-reopen" @click="reopen">
                    Reopen the day
                </a-button>
            </div>
        </div>
        <div v-else-if="canStartShift" class="space-y-4 py-2">
            <p class="text-sm text-gray-600">
                Your drawer isn't open at this store. Count the cash you
                start with to open it; other cashiers' drawers are their own.
            </p>
            <a-alert
                v-if="reviewPendingSince"
                type="info"
                show-icon
                :message="`Your shift that ended at ${reviewPendingSince} is waiting for a manager to review its count. You can still start a new one.`"
                data-testid="shift-review-pending"
            />
            <div class="flex flex-col gap-1">
                <span class="text-sm text-gray-700">Opening cash</span>
                <a-input
                    v-model:value="openingCash"
                    inputmode="decimal"
                    placeholder="0.00"
                    @keydown="numberKeysOnly"
                    @paste="numberPasteOnly"
                />
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-sm text-gray-700">Note (optional)</span>
                <a-input v-model:value="reason" placeholder="e.g. Starting float" />
            </div>
            <ClockAlongField v-model="clock" mode="in" :status="page.props.shiftStatus?.clock" :error="clockError" />
            <div class="flex justify-end gap-2 pt-2">
                <a-button @click="close">Cancel</a-button>
                <a-button type="primary" :loading="saving" :disabled="clockPinMissing" data-testid="start-shift-submit" @click="submit">
                    Open drawer
                </a-button>
            </div>
        </div>
        <div v-else class="space-y-4 py-2">
            <p v-if="mayStartAtAll && reviewPendingSince" class="text-sm text-gray-600" data-testid="shift-review-blocks">
                Your shift that ended at {{ reviewPendingSince }} is waiting
                for a manager to review its count. This store has you wait
                for that before starting another.
            </p>
            <p v-else class="text-sm text-gray-600">
                Your drawer isn't open at this store, and your role can't
                open one. Ask a manager or admin to give you the Start own
                shift permission.
            </p>
            <div class="flex justify-end">
                <a-button @click="close">Close</a-button>
            </div>
        </div>
    </a-modal>
</template>
