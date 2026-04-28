<script setup>
import { ref, watch, computed } from "vue";
import { useHelpers } from "@/Composables/useHelpers";

const emit = defineEmits(["close", "apply"]);

const props = defineProps({
    openModal: {
        type: Boolean,
        default: false,
    },
    selectedCustomer: {
        type: Object,
        default: null,
    },
    loyaltyCfg: {
        type: Object,
        default: () => ({
            points_per_currency_unit: 100,
            max_redemption_percent_of_eligible_net: 50,
            min_points_redemption: 1,
        }),
    },
    /** Max points server rules allow on this cart (computed in parent). */
    maxRedeemablePoints: {
        type: Number,
        default: 0,
    },
    /** Net subtotal after order discount (before VAT & loyalty); for estimate only. */
    eligibleNetPeso: {
        type: Number,
        default: 0,
    },
    initialPoints: {
        type: Number,
        default: 0,
    },
    patching: {
        type: Boolean,
        default: false,
    },
});

const { formattedTotal } = useHelpers();

const localPoints = ref(0);

const ppcu = computed(
    () => Number(props.loyaltyCfg?.points_per_currency_unit) || 100,
);

const maxPctFrac = computed(
    () =>
        (Number(props.loyaltyCfg?.max_redemption_percent_of_eligible_net) ||
            50) / 100,
);

const minPoints = computed(
    () => Math.max(1, Number(props.loyaltyCfg?.min_points_redemption) || 1),
);

/** Policy cap for new/adjusted redemption; may be below points already pending on the sale. */
const ruleCapPoints = computed(() =>
    Math.max(0, Math.floor(Number(props.maxRedeemablePoints) || 0)),
);

const initialPointsFloored = computed(() =>
    Math.max(0, Math.floor(Number(props.initialPoints) || 0)),
);

/** Allow entering up to the higher of policy max or what's already redeemed (adjust/clear). */
const numericInputMax = computed(() =>
    Math.max(ruleCapPoints.value, initialPointsFloored.value),
);

const canInteractWithPoints = computed(
    () =>
        props.maxRedeemablePoints > 0 || initialPointsFloored.value > 0,
);

/** Policy max is zero but points are already pending — user can only reduce (e.g. clear). */
const clearOnlyMode = computed(
    () =>
        ruleCapPoints.value <= 0 && initialPointsFloored.value > 0,
);

watch(
    () => props.openModal,
    (open) => {
        if (open) {
            localPoints.value =
                Number(props.initialPoints) >= 0
                    ? Number(props.initialPoints)
                    : 0;
        }
    },
);

const cappedNet = computed(() =>
    Math.max(0, Number(props.eligibleNetPeso) || 0),
);

const maxPesoByPercent = computed(() =>
    round2(cappedNet.value * maxPctFrac.value),
);

const clampedDraftPoints = computed(() => {
    const maxPts = numericInputMax.value;
    let n = Math.max(
        0,
        Math.min(Math.floor(Number(localPoints.value) || 0), maxPts),
    );
    if (n > 0 && n < minPoints.value) {
        n = 0;
    }
    return n;
});

/** Client-side estimate aligned with LoyaltyRedemptionService rules (approximate). */
const estimatedPesoDiscount = computed(() => {
    const pts = clampedDraftPoints.value;
    if (pts <= 0 || cappedNet.value <= 0) return 0;
    const raw = pts / ppcu.value;
    return round2(Math.min(raw, cappedNet.value, maxPesoByPercent.value));
});

function round2(n) {
    return Math.round(n * 100) / 100;
}

const customerBalancePts = computed(
    () =>
        Number(props.selectedCustomer?.loyalty_points ?? 0) || 0,
);

function handleApply() {
    const n = clampedDraftPoints.value;
    localPoints.value = n;
    emit("apply", n);
}

function handleCancel() {
    emit("close");
}
</script>

<template>
    <a-modal
        :visible="openModal"
        title="Loyalty redemption"
        width="460px"
        :mask-closable="false"
        :footer="null"
        @cancel="handleCancel"
    >
        <div
            v-if="!selectedCustomer"
            class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
        >
            Select a customer to redeem loyalty points.
        </div>

        <div v-else class="space-y-4">
            <div class="text-sm text-gray-700">
                <span class="font-medium">Balance:</span>
                {{ customerBalancePts.toLocaleString() }} pts
                <span class="mx-2 text-gray-400">·</span>
                <span class="font-medium">Max redeem (policy, this sale):</span>
                {{ ruleCapPoints.toLocaleString() }}
                pts
                <span
                    v-if="numericInputMax > ruleCapPoints && initialPointsFloored > 0"
                    class="text-gray-500"
                >
                    · You can adjust up to
                    {{ numericInputMax.toLocaleString() }}
                    pts currently on this order.
                </span>
            </div>

            <div
                v-if="!canInteractWithPoints"
                class="rounded border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700"
            >
                No redemption is available yet (eligible order amount must be
                greater than zero or the customer has no points).
            </div>

            <template v-else>
                <div v-if="clearOnlyMode" class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    Current order no longer allows new loyalty credit from
                    policy caps, but points are still pending. Set to
                    <strong>0</strong> and Apply to remove redemption.
                </div>
                <div>
                    <div class="mb-1 block text-sm text-gray-700">
                        Points to redeem
                    </div>
                    <a-input-number
                        v-model:value="localPoints"
                        class="max-w-[200px]"
                        :min="0"
                        :max="numericInputMax"
                        :disabled="patching"
                        placeholder="0"
                    />
                    <div class="mt-1 text-xs text-gray-500">
                        Minimum
                        {{ minPoints.toLocaleString() }}
                        pts when redeeming any amount above zero.
                    </div>
                </div>

                <div class="rounded border border-amber-100 bg-amber-50/80 p-3 text-sm text-amber-950">
                    <div>
                        Estimated discount:
                        <span class="font-semibold">{{ formattedTotal(estimatedPesoDiscount) }}</span>
                        <span class="text-amber-800/90">
                            (~{{ clampedDraftPoints.toLocaleString() }} pts at
                            {{ ppcu }} pts = ₱1, capped by order rules)
                        </span>
                    </div>
                    <div
                        v-if="cappedNet <= 0 && clampedDraftPoints > 0"
                        class="mt-1 text-xs text-amber-800/90"
                    >
                        Estimated amount may be zero until there is an eligible
                        line total.
                    </div>
                    <div class="mt-1 text-xs text-amber-800/85">
                        Final discount is computed on save and may reflect rounding.
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <a-button :disabled="patching" @click="handleCancel">
                Cancel
            </a-button>
            <a-button
                type="primary"
                class="bg-amber-600 hover:bg-amber-500 focus:bg-amber-500"
                :disabled="
                    patching ||
                    !selectedCustomer ||
                    !canInteractWithPoints
                "
                :loading="patching"
                @click="handleApply"
            >
                Apply
            </a-button>
        </div>
    </a-modal>
</template>
