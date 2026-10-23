<script setup>
import { computed } from "vue";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import WalletLayout from "./components/WalletLayout.vue";
import BalanceCards from "./components/balances/BalanceCards.vue";
import AllMoneyTable from "./components/balances/AllMoneyTable.vue";
import AllStoresTable from "./components/balances/AllStoresTable.vue";
import { useWalletQuery } from "./composables/useWalletQuery";

/**
 * Where money is: the cash drawer and each payment channel (GCash, bank, card terminal), with
 * the channels themselves under Manage channels and, for finance managers, every peso in and out.
 */
const props = defineProps({
    tab: { type: String, default: "balances" },
    canSeeBalances: { type: Boolean, default: false },
    drawerExpected: { type: Number, default: null },
    channelBalances: { type: Array, default: () => [] },
    todayIsClosed: { type: Boolean, default: false },
    canViewAllMoney: { type: Boolean, default: false },
    allMoney: { type: Object, default: null },
    /** Owners and admins: every store side by side. */
    canSeeAllStores: { type: Boolean, default: false },
    allStores: { type: Object, default: null },
    activeLocation: { type: Object, default: null },
});

const { hasPermission } = usePermissionsV2();
const { locationId, keepQuery, visit } = useWalletQuery(() => props.activeLocation);

const tabs = computed(() => [
    ...(props.canSeeBalances ? [{ key: "balances", label: "Balances" }] : []),
    ...(hasPermission("payment-card-types.index") ? [{ key: "channels", label: "Manage channels" }] : []),
    ...(props.canSeeAllStores ? [{ key: "stores", label: "All stores" }] : []),
    ...(props.canViewAllMoney ? [{ key: "all", label: "All money" }] : []),
]);

function openTab(key) {
    if (key === "channels") return visit("payment-card-types.index");
    visit("wallet.balances", { tab: key === "balances" ? undefined : key });
}
</script>

<template>
    <WalletLayout title="Where money is" active="balances" :active-location="activeLocation">
        <a-radio-group
            v-if="tabs.length > 1"
            :value="tab"
            button-style="solid"
            class="mb-4"
            data-testid="balances-tabs"
            @change="(e) => openTab(e.target.value)"
        >
            <a-radio-button v-for="t in tabs" :key="t.key" :value="t.key" :data-testid="`balances-tab-${t.key}`">{{ t.label }}</a-radio-button>
        </a-radio-group>

        <AllStoresTable v-if="tab === 'stores' && allStores" :all-stores="allStores" />
        <template v-else-if="tab === 'all' && allMoney">
            <AllMoneyTable :all-money="allMoney" :keep-query="keepQuery" />
        </template>
        <BalanceCards
            v-else-if="canSeeBalances"
            :channels="channelBalances"
            :drawer-expected="drawerExpected"
            :active-location-id="locationId"
            :is-shift-closed="todayIsClosed"
            @count="visit('wallet.drawer')"
        />
        <div v-else class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">
            Open <a-button type="link" class="h-auto p-0" @click="openTab('channels')">Manage channels</a-button> to set up the
            store's card terminals, e-wallets and banks.
        </div>
    </WalletLayout>
</template>
