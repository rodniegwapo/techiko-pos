<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { message as antMessage } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";
import { CHANNEL_KIND_LABELS, PLACE_GROUPS } from "../walletLabels";
import BalanceTotals from "./BalanceTotals.vue";
import MoneyByPlaceTables from "./MoneyByPlaceTables.vue";

/**
 * What the drawer and each payment channel hold now. The drawer shows today's Expected, the same
 * figure as Today's drawer. "Set actual" records the difference from what a channel really shows
 * (its app or bank statement) as a correction.
 */
const props = defineProps({
    /** [{ id, name, kind, is_active, balance }] */
    channels: { type: Array, default: () => [] },
    /** Today's Expected in the drawer. */
    drawerExpected: { type: Number, default: null },
    /** Supervisors and up: the places as a table, by cashier (MoneyByPlace). Shown instead of the cards. */
    moneyByPlace: { type: Object, default: null },
    activeLocationId: { type: Number, default: null },
    activeLocation: { type: Object, default: null },
    isShiftClosed: { type: Boolean, default: false },
});

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();

// Cards in the same order as the groups: the drawer, e-wallets, then banks and card terminals.
const groupOrder = (kind) => PLACE_GROUPS.findIndex((g) => g.kinds.includes(kind));
const orderedChannels = computed(() => [...props.channels].sort((a, b) => groupOrder(a.kind) - groupOrder(b.kind)));

const canAdjust = computed(() => hasPermission("wallet-cash-ledger.store") && !props.isShiftClosed);

const adjusting = ref(null);
const actual = ref(null);
const saving = ref(false);

const difference = computed(() =>
    adjusting.value && actual.value !== null && actual.value !== ""
        ? Math.round((Number(actual.value) - Number(adjusting.value.balance)) * 100) / 100
        : null,
);

function openAdjust(channel) {
    adjusting.value = channel;
    actual.value = channel.balance;
}

function saveAdjust() {
    const diff = difference.value;
    if (diff === null) {
        antMessage.warning("Enter the balance the channel shows.");
        return;
    }
    if (diff === 0) {
        adjusting.value = null;
        antMessage.info("Already matches; nothing to record.");
        return;
    }
    const channel = adjusting.value;
    saving.value = true;
    router.post(
        getRoute("wallet-cash-ledger.store"),
        {
            direction: diff > 0 ? "in" : "out",
            amount: Math.abs(diff),
            kind: "adjustment",
            payment_card_type_id: channel.id,
            movement_date: dayjs().format("YYYY-MM-DD"),
            notes: `Balance set to ${formattedTotal(Number(actual.value))} (was ${formattedTotal(channel.balance)})`,
            location_id: props.activeLocationId,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                adjusting.value = null;
                antMessage.success(`${channel.name} balance updated.`);
            },
            onError: (errors) => {
                const first = Object.values(errors ?? {})[0];
                antMessage.error((Array.isArray(first) ? first[0] : first) || "Could not update the balance.");
            },
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <div data-testid="channel-balances">
        <BalanceTotals :channels="channels" :drawer-expected="drawerExpected" />
        <MoneyByPlaceTables
            v-if="moneyByPlace"
            :money-by-place="moneyByPlace"
            :can-adjust="canAdjust"
            :active-location="activeLocation"
            @adjust="openAdjust"
        />
        <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-if="drawerExpected !== null" class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm" data-testid="drawer-balance">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-sm font-medium text-gray-700">Cash drawer</div>
                        <div class="text-xs text-gray-500">Expected today</div>
                    </div>
                </div>
                <div
                    class="mt-1 font-mono text-lg font-bold tabular-nums"
                    :class="drawerExpected >= 0 ? 'text-gray-900' : 'text-red-700'"
                    data-testid="drawer-balance-amount"
                >
                    {{ formattedTotal(drawerExpected) }}
                </div>
            </div>
            <div
                v-for="channel in orderedChannels"
                :key="channel.id"
                class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm"
                :class="{ 'opacity-60': !channel.is_active }"
                :data-testid="`channel-balance-${channel.id}`"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-gray-700">{{ channel.name }}</div>
                        <div class="text-xs text-gray-500">
                            {{ CHANNEL_KIND_LABELS[channel.kind] ?? channel.kind }}<template v-if="!channel.is_active"> · inactive</template>
                        </div>
                    </div>
                    <a-button
                        v-if="canAdjust"
                        size="small"
                        type="link"
                        class="!px-0"
                        @click="openAdjust(channel)"
                    >
                        Set actual
                    </a-button>
                </div>
                <div
                    class="mt-1 font-mono text-lg font-bold tabular-nums"
                    :class="channel.balance >= 0 ? 'text-gray-900' : 'text-red-700'"
                    data-testid="channel-balance-amount"
                >
                    {{ formattedTotal(channel.balance) }}
                </div>
            </div>
        </div>
        <p v-if="channels.length" class="mb-0 mt-2 text-xs text-gray-500">
            A channel's balance is what its sales brought in plus the money moved on it. If it doesn't match its app
            or statement, use "Set actual".
        </p>

        <a-modal
            :visible="!!adjusting"
            :title="adjusting ? `Set ${adjusting.name} balance` : ''"
            ok-text="Save"
            :confirm-loading="saving"
            destroy-on-close
            @ok="saveAdjust"
            @cancel="adjusting = null"
        >
            <div v-if="adjusting" class="flex flex-col gap-3 pt-2">
                <div class="text-sm text-gray-600">
                    Recorded balance: <span class="font-semibold">{{ formattedTotal(adjusting.balance) }}</span>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700">Balance the channel shows now</label>
                    <a-input-number
                        v-model:value="actual"
                        :step="0.01"
                        :precision="2"
                        class="w-full"
                        data-testid="channel-actual-balance"
                    />
                </div>
                <div v-if="difference !== null && difference !== 0" class="text-sm" :class="difference > 0 ? 'text-green-700' : 'text-red-700'">
                    Records a correction of {{ difference > 0 ? "+" : "−" }}{{ formattedTotal(Math.abs(difference)) }} today.
                </div>
            </div>
        </a-modal>
    </div>
</template>
