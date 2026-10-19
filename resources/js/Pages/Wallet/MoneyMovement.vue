<script setup>
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";

import WalletShell from "@/Pages/Wallet/WalletShell.vue";
import CashLedgerPanel from "@/Pages/Wallet/partials/CashLedgerPanel.vue";
import AllMoneyTable from "@/Pages/Wallet/partials/AllMoneyTable.vue";
import ChannelBalances from "@/Pages/Wallet/partials/ChannelBalances.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute } = useDomainRoutes();

const props = defineProps({
    cardTypes: {
        type: Array,
        default: () => [],
    },
    walletCashTotals: {
        type: Object,
        default: () => ({ today_total: 0, yesterday_total: 0 }),
    },
    walletCreditTotals: {
        type: Object,
        default: () => ({ today_total: 0, yesterday_total: 0 }),
    },
    ledger: {
        type: Object,
        default: null,
    },
    runningCashBalance: {
        type: Number,
        default: null,
    },
    activeLocation: {
        type: Object,
        default: () => null,
    },
    cashControl: {
        type: Object,
        default: () => null,
    },
    walletPageMode: {
        type: String,
        default: "money-movement",
    },
    canViewMoneyMovement: {
        type: Boolean,
        default: false,
    },
    canViewCardTypes: {
        type: Boolean,
        default: true,
    },
    canViewAllMoney: {
        type: Boolean,
        default: false,
    },
    allMoney: {
        type: Object,
        default: null,
    },
    channelBalances: {
        type: Array,
        default: () => [],
    },
});

const showAllMoney = computed(() => !!props.allMoney);

/** Store and business date stay put when switching views or filtering. */
const keepQuery = computed(() => {
    const q = queryObjectFromPageUrl(page.url);
    return Object.fromEntries(
        ["location_id", "business_date"].filter((k) => q[k]).map((k) => [k, q[k]]),
    );
});

function switchView(view) {
    router.get(
        getRoute("wallet.money-movement"),
        { ...keepQuery.value, view: view === "all" ? "all" : undefined },
        { preserveScroll: true },
    );
}

/** @param {string} url */
function queryObjectFromPageUrl(url) {
    if (!url || typeof url !== "string") {
        return {};
    }
    const idx = url.indexOf("?");
    if (idx === -1) {
        return {};
    }
    return Object.fromEntries(new URLSearchParams(url.slice(idx + 1)));
}

const activeLocationId = computed(() => {
    const q = queryObjectFromPageUrl(page.url);
    if (q.location_id && /^[0-9]+$/.test(String(q.location_id))) {
        return Number(q.location_id);
    }

    const fromWallet = props.activeLocation?.id;
    if (fromWallet != null) {
        return Number(fromWallet);
    }

    const fromShared = page.props?.currentLocation?.id;
    if (fromShared != null) {
        return Number(fromShared);
    }

    return null;
});

const isShiftClosed = computed(() => !!props.cashControl?.is_closed);
</script>

<template>
    <WalletShell v-bind="props" :is-money-movement-page="true">
        <template #primary>
            <div class="mt-6 max-w-7xl w-full min-w-0">
                <a-radio-group
                    v-if="canViewAllMoney"
                    :value="showAllMoney ? 'all' : 'drawer'"
                    button-style="solid"
                    class="mb-4"
                    data-testid="money-view"
                    @change="(e) => switchView(e.target.value)"
                >
                    <a-radio-button value="drawer">Drawer &amp; channels</a-radio-button>
                    <a-radio-button value="all">All money</a-radio-button>
                </a-radio-group>
                <AllMoneyTable v-if="showAllMoney" :all-money="allMoney" :keep-query="keepQuery" />
                <ChannelBalances
                    v-if="!showAllMoney && ledger"
                    :channels="channelBalances"
                    :drawer-balance="runningCashBalance"
                    :active-location-id="activeLocationId"
                    :is-shift-closed="isShiftClosed"
                />
                <CashLedgerPanel
                    v-if="!showAllMoney && ledger"
                    :movements="ledger.movements"
                    :filters="ledger.filters"
                    :ledger-balance="ledger.ledgerBalance"
                    :rail-card-types="ledger.railCardTypes"
                    :active-location-id="activeLocationId"
                    :is-shift-closed="isShiftClosed"
                    :running-cash-balance="runningCashBalance"
                    :business-date="cashControl?.business_date ?? null"
                />
            </div>
        </template>
    </WalletShell>
</template>
