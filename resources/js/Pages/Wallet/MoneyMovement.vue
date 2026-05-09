<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

import WalletShell from "@/Pages/Wallet/WalletShell.vue";
import CashLedgerPanel from "@/Pages/Wallet/partials/CashLedgerPanel.vue";

const page = usePage();

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
});

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
            <div class="mt-6 max-w-7xl">
                <CashLedgerPanel
                    v-if="ledger"
                    :movements="ledger.movements"
                    :filters="ledger.filters"
                    :ledger-balance="ledger.ledgerBalance"
                    :rail-card-types="ledger.railCardTypes"
                    :active-location-id="activeLocationId"
                    :is-shift-closed="isShiftClosed"
                    :running-cash-balance="runningCashBalance"
                />
            </div>
        </template>
    </WalletShell>
</template>
