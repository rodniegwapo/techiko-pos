<script setup>
import { ref, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { numberKeysOnly, numberPasteOnly } from "@/Composables/numericInput";

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const page = usePage();

const props = defineProps({
    visible: { type: Boolean, default: false },
});

const emit = defineEmits(["update:visible", "started"]);

const canStartShift = computed(() =>
    hasPermission("wallet-cash-ledger.opening-cash.store"),
);

const businessDate = computed(
    () =>
        page.props.shiftStatus?.business_date ||
        new Date().toISOString().slice(0, 10),
);
const locationId = computed(() => page.props.currentLocation?.id ?? null);

const openingCash = ref(0);
const reason = ref("");
const saving = ref(false);

function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return err?.response?.data?.message || err?.message || "Could not start the shift.";
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

function reopen() {
    if (!reopenReason.value.trim()) return;
    saving.value = true;
    router.post(
        getRoute("wallet-cash-ledger.reopen-shift"),
        { location_id: locationId.value, business_date: businessDate.value, reason: reopenReason.value.trim() },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                reopenReason.value = "";
                notification.success({ message: "Shift reopened." });
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
        await axios.post(getRoute("wallet-cash-ledger.opening-cash.store"), {
            location_id: locationId.value,
            business_date: businessDate.value,
            opening_cash: Number(openingCash.value || 0),
            reason: reason.value || null,
        });
        notification.success({ message: "Shift started." });
        openingCash.value = 0;
        reason.value = "";
        emit("update:visible", false);
        emit("started");
    } catch (e) {
        notification.error({ message: firstValidationMessage(e) });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <a-modal
        :visible="visible"
        :title="isClosed ? 'Today\'s shift was ended' : 'Start today\'s shift'"
        width="420px"
        :mask-closable="false"
        :footer="null"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <div v-if="isClosed" class="space-y-4 py-2" data-testid="shift-closed">
            <p class="text-sm text-gray-600">
                This location's shift for today was already ended<template v-if="closedAt"> at {{ closedAt }}</template>,
                so a new one can't be started. Payments stay blocked until it is reopened.
            </p>
            <p v-if="!canReopen" class="text-sm text-gray-600">
                Ask a manager or admin to reopen it.
            </p>
            <div v-else class="flex flex-col gap-1">
                <span class="text-sm text-gray-700">Why are you reopening it?</span>
                <a-input
                    v-model:value="reopenReason"
                    placeholder="e.g. counted wrong"
                    :maxlength="255"
                    data-testid="shift-reopen-reason"
                    @press-enter="reopen"
                />
                <span v-if="closedByName" class="text-xs text-gray-500">{{ closedByName }} ended this shift. Your name and reason are kept in the log.</span>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <a-button @click="close">Close</a-button>
                <a-button v-if="canReopen" type="primary" :loading="saving" :disabled="!reopenReason.trim()" data-testid="shift-reopen" @click="reopen">
                    Reopen shift
                </a-button>
            </div>
        </div>
        <div v-else-if="canStartShift" class="space-y-4 py-2">
            <p class="text-sm text-gray-600">
                No shift is open yet for this location today. Declare the
                cash you're starting the drawer with to open it.
            </p>
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
            <div class="flex justify-end gap-2 pt-2">
                <a-button @click="close">Cancel</a-button>
                <a-button type="primary" :loading="saving" @click="submit">
                    Start shift
                </a-button>
            </div>
        </div>
        <div v-else class="space-y-4 py-2">
            <p class="text-sm text-gray-600">
                No shift is open yet for this location today. Ask a manager
                or admin to open it from Wallet → Money Movement before
                taking payments.
            </p>
            <div class="flex justify-end">
                <a-button @click="close">Close</a-button>
            </div>
        </div>
    </a-modal>
</template>
