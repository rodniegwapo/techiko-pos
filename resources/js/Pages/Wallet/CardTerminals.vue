<script setup>
import { ref, computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { router, usePage } from "@inertiajs/vue3";
import {
    IconPlus,
    IconReportMoney,
    IconEdit,
    IconTrash,
} from "@tabler/icons-vue";
import axios from "axios";
import { notification } from "ant-design-vue";

import ContentLayout from "@/Components/ContentLayout.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import WalletShell from "@/Pages/Wallet/WalletShell.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();

const page = usePage();

function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return err?.response?.data?.message || err?.message || null;
}

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
        default: "card-types",
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

const activeBusinessDate = computed(() => {
    const q = queryObjectFromPageUrl(page.url);
    if (q.business_date) {
        return String(q.business_date);
    }

    return (
        props.cashControl?.business_date ||
        new Date().toISOString().slice(0, 10)
    );
});

const rows = computed(() => props.cardTypes ?? []);

const isMdUp = useMediaQuery("(min-width: 768px)");

const cardTypeModalWidth = computed(() =>
    isMdUp.value ? 720 : "calc(100vw - 24px)",
);
const cardTypeModalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

const modalOpen = ref(false);
const editing = ref(null);
const formName = ref("");
const formActive = ref(true);
const saving = ref(false);

function openCreate() {
    editing.value = null;
    formName.value = "";
    formActive.value = true;
    modalOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    formName.value = row.name;
    formActive.value = !!row.is_active;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
}

async function save() {
    const name = String(formName.value || "").trim();
    if (!name) {
        notification.warning({ message: "Name is required." });
        return;
    }
    saving.value = true;
    try {
        if (editing.value?.id) {
            await axios.put(
                getRoute("payment-card-types.update", {
                    paymentCardType: editing.value.id,
                }),
                {
                    location_id: activeLocationId.value,
                    name,
                    is_active: formActive.value,
                },
            );
            notification.success({ message: "Card type updated." });
        } else {
            await axios.post(getRoute("payment-card-types.store"), {
                location_id: activeLocationId.value,
                name,
                sort_order: 0,
            });
            notification.success({ message: "Card type created." });
        }
        closeModal();
        router.reload({
            only: [
                "cardTypes",
                "walletCashTotals",
                "walletCreditTotals",
                "ledger",
                "runningCashBalance",
                "cashControl",
            ],
        });
    } catch (e) {
        const msg = firstValidationMessage(e) || "Could not save card type.";
        notification.error({ message: msg });
    } finally {
        saving.value = false;
    }
}

const deletingId = ref(null);

async function remove(row) {
    deletingId.value = row.id;
    try {
        await axios.delete(
            getRoute("payment-card-types.destroy", {
                paymentCardType: row.id,
            }),
            {
                params: {
                    location_id: activeLocationId.value,
                },
            },
        );
        notification.success({ message: "Done." });
        router.reload({
            only: [
                "cardTypes",
                "walletCashTotals",
                "walletCreditTotals",
                "ledger",
                "runningCashBalance",
                "cashControl",
            ],
        });
    } catch (e) {
        const msg = firstValidationMessage(e) || "Could not remove card type.";
        notification.error({ message: msg });
    } finally {
        deletingId.value = null;
    }
}

const columns = [
    { title: "Name", dataIndex: "name", key: "name" },
    {
        title: "Status",
        key: "is_active",
        width: 100,
    },
    { title: "", key: "actions", width: 220 },
];

function goToMoneyDetailsPage(record) {
    const q = {};
    if (activeLocationId.value != null) {
        q.location_id = activeLocationId.value;
    }
    if (activeBusinessDate.value) {
        q.business_date = activeBusinessDate.value;
    }
    router.get(
        getRoute("payment-card-types.details", {
            paymentCardType: record.id,
        }),
        q,
    );
}

const showMobileSecondaryActions = computed(
    () =>
        hasPermission("payment-card-types.update") ||
        hasPermission("payment-card-types.destroy"),
);
</script>

<template>
    <WalletShell v-bind="props" :is-money-movement-page="false">
        <template #primary>
            <div class="mt-6 w-full min-w-0 max-w-7xl">
                <div class="space-y-4">
                    <div
                        class="mb-4 max-w-full min-w-0 rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm md:max-w-7xl"
                    >
                        <div class="text-base font-semibold text-gray-900">
                            Credit
                        </div>
                        <div class="mb-3 text-xs text-gray-500">
                            Paid credit sales (charge to account)
                        </div>
                        <div
                            class="grid min-w-0 max-w-full grid-cols-1 gap-3 md:max-w-md md:grid-cols-2"
                        >
                            <div
                                class="min-w-0 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                            >
                                <div class="text-xs uppercase text-gray-500">
                                    Today
                                </div>
                                <div class="text-lg font-semibold text-green-700">
                                    {{
                                        formattedTotal(
                                            Number(
                                                walletCreditTotals.today_total,
                                            ) || 0,
                                        )
                                    }}
                                </div>
                            </div>
                            <div
                                class="min-w-0 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2"
                            >
                                <div class="text-xs uppercase text-gray-500">
                                    Yesterday
                                </div>
                                <div class="text-lg font-semibold text-gray-800">
                                    {{
                                        formattedTotal(
                                            Number(
                                                walletCreditTotals.yesterday_total,
                                            ) || 0,
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <ContentLayout
                        title="Payment card types"
                        filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
                    >
                        <template #filters>
                            <div
                                v-if="hasPermission('payment-card-types.store')"
                                class="w-full md:w-auto"
                            >
                                <a-button
                                    type="primary"
                                    class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
                                    @click="openCreate"
                                >
                                    <template #icon>
                                        <IconPlus class="h-4 w-4" />
                                    </template>
                                    Add card type
                                </a-button>
                            </div>
                        </template>

                        <template #table>
                            <a-table
                                v-if="isMdUp"
                                :columns="columns"
                                :data-source="rows"
                                :pagination="false"
                                row-key="id"
                                :locale="{
                                    emptyText:
                                        'No card types yet. Add one to use Pay in Card on Sales.',
                                }"
                            >
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'is_active'">
                                        <a-tag
                                            :color="
                                                record.is_active
                                                    ? 'green'
                                                    : 'default'
                                            "
                                        >
                                            {{
                                                record.is_active
                                                    ? "Active"
                                                    : "Inactive"
                                            }}
                                        </a-tag>
                                    </template>
                                    <template
                                        v-else-if="column.key === 'actions'"
                                    >
                                        <a-space>
                                            <IconTooltipButton
                                                v-if="
                                                    hasPermission(
                                                        'payment-card-types.money',
                                                    )
                                                "
                                                name="View payment details"
                                                hover="hover:bg-emerald-600"
                                                @click="
                                                    goToMoneyDetailsPage(
                                                        record,
                                                    )
                                                "
                                            >
                                                <IconReportMoney
                                                    size="20"
                                                    class="mx-auto"
                                                />
                                            </IconTooltipButton>
                                            <IconTooltipButton
                                                v-if="
                                                    hasPermission(
                                                        'payment-card-types.update',
                                                    )
                                                "
                                                name="Edit card type"
                                                hover="hover:bg-blue-500"
                                                @click="openEdit(record)"
                                            >
                                                <IconEdit
                                                    size="20"
                                                    class="mx-auto"
                                                />
                                            </IconTooltipButton>
                                            <IconTooltipButton
                                                v-if="
                                                    hasPermission(
                                                        'payment-card-types.destroy',
                                                    )
                                                "
                                                name="Remove card type"
                                                hover="hover:bg-red-600"
                                                :loading="
                                                    deletingId === record.id
                                                "
                                                @click="remove(record)"
                                            >
                                                <IconTrash
                                                    size="20"
                                                    class="mx-auto"
                                                />
                                            </IconTooltipButton>
                                        </a-space>
                                    </template>
                                </template>
                            </a-table>

                            <div v-else class="px-2 py-2 md:px-0">
                                <a-spin :spinning="deletingId != null">
                                    <div
                                        v-if="!rows.length"
                                        class="py-12 text-center text-sm text-gray-500"
                                    >
                                        No card types yet. Add one to use Pay in
                                        Card on Sales.
                                    </div>
                                    <div v-else class="flex flex-col gap-3">
                                        <div
                                            v-for="record in rows"
                                            :key="record.id"
                                            class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                                        >
                                            <div class="px-4 py-3">
                                                <div
                                                    class="truncate text-base font-semibold text-gray-900"
                                                >
                                                    {{ record.name }}
                                                </div>
                                                <div
                                                    class="mt-2 flex flex-wrap items-center gap-2"
                                                >
                                                    <a-tag
                                                        :color="
                                                            record.is_active
                                                                ? 'green'
                                                                : 'default'
                                                        "
                                                        class="m-0 text-xs"
                                                    >
                                                        {{
                                                            record.is_active
                                                                ? "Active"
                                                                : "Inactive"
                                                        }}
                                                    </a-tag>
                                                </div>
                                            </div>

                                            <div
                                                class="border-t border-gray-100 px-4 py-3"
                                            >
                                                <div
                                                    class="flex flex-col gap-2"
                                                >
                                                    <a-button
                                                        v-if="
                                                            hasPermission(
                                                                'payment-card-types.money',
                                                            )
                                                        "
                                                        class="flex items-center justify-center gap-2"
                                                        @click="
                                                            goToMoneyDetailsPage(
                                                                record,
                                                            )
                                                        "
                                                    >
                                                        <template #icon>
                                                            <IconReportMoney
                                                                size="18"
                                                            />
                                                        </template>
                                                        View payment details
                                                    </a-button>
                                                    <div
                                                        v-if="
                                                            showMobileSecondaryActions
                                                        "
                                                        class="grid grid-cols-2 gap-2"
                                                    >
                                                        <a-button
                                                            v-if="
                                                                hasPermission(
                                                                    'payment-card-types.update',
                                                                )
                                                            "
                                                            class="flex items-center justify-center gap-2"
                                                            @click="
                                                                openEdit(record)
                                                            "
                                                        >
                                                            <template #icon>
                                                                <IconEdit
                                                                    size="18"
                                                                />
                                                            </template>
                                                            Edit
                                                        </a-button>
                                                        <a-button
                                                            v-if="
                                                                hasPermission(
                                                                    'payment-card-types.destroy',
                                                                )
                                                            "
                                                            class="flex items-center justify-center gap-2"
                                                            danger
                                                            :loading="
                                                                deletingId ===
                                                                record.id
                                                            "
                                                            @click="
                                                                remove(record)
                                                            "
                                                        >
                                                            <template #icon>
                                                                <IconTrash
                                                                    size="18"
                                                                />
                                                            </template>
                                                            Remove
                                                        </a-button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a-spin>
                            </div>
                        </template>
                    </ContentLayout>
                </div>
            </div>
        </template>
        <template #after>
            <a-modal
                v-model:visible="modalOpen"
                :title="editing ? 'Edit card type' : 'Add card type'"
                :width="cardTypeModalWidth"
                :style="cardTypeModalRootStyle"
                centered
                :confirm-loading="saving"
                ok-text="Save"
                destroy-on-close
                @ok="save"
                @cancel="closeModal"
            >
                <div class="flex flex-col gap-4 pt-2">
                    <div>
                        <div class="text-sm text-gray-600 mb-1">
                            Display name
                        </div>
                        <a-input
                            v-model:value="formName"
                            placeholder="e.g. BDO POS, Visa terminal"
                            maxlength="255"
                        />
                    </div>
                    <div
                        v-if="editing"
                        class="flex items-center justify-between gap-4"
                    >
                        <span class="text-sm text-gray-600">Active</span>
                        <a-switch v-model:checked="formActive" />
                    </div>
                </div>
            </a-modal>
        </template>
    </WalletShell>
</template>
