<script setup>
import { ref, computed } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { IconCreditCard, IconArrowLeft, IconPlus } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useCredit } from "@/Composables/useCredit";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import PaymentHistoryTable from "./components/PaymentHistoryTable.vue";
import OutstandingInvoicesTable from "./components/OutstandingInvoicesTable.vue";
import RecordPaymentModal from "./components/RecordPaymentModal.vue";
import CreditTransactionModal from "./components/CreditTransactionModal.vue";
import CreditLimitModal from "./components/CreditLimitModal.vue";

const page = usePage();
const { formattedTotal } = useHelpers();
const { loading: creditLoading } = useCredit();

const props = defineProps({
    customer: Object,
    domain: Object,
    paymentHistory: Array,
    outstandingInvoices: Array,
    overdueTransactions: Array,
    overdueAmount: Number,
    availableCredit: Number,
});

const showRecordPaymentModal = ref(false);
const showCreditTransactionModal = ref(false);
const showCreditLimitModal = ref(false);

const handleBack = () => {
    router.visit(
        page.props.ziggy?.urls?.["domains.credits.index"]?.replace(
            "{domain}",
            props.domain.name_slug
        ) || `/domains/${props.domain.name_slug}/credits`
    );
};

const handleRecordPayment = () => {
    showRecordPaymentModal.value = true;
};

const handleAddTransaction = () => {
    showCreditTransactionModal.value = true;
};

const handleEditCreditLimit = () => {
    showCreditLimitModal.value = true;
};

const handleModalClose = () => {
    showRecordPaymentModal.value = false;
    showCreditTransactionModal.value = false;
    showCreditLimitModal.value = false;
};

const handleSaved = () => {
    handleModalClose();
    router.reload();
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="`Credit Details - ${customer?.name}`" />
        <ContentHeader class="mb-8" title="Customer Credit Details" />

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Back Button -->
            <a-button @click="handleBack" class="mb-4 flex">
                <IconArrowLeft />

                Back to Credits
            </a-button>

            <!-- Customer Info Card -->
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">
                            {{ customer?.name }}
                        </h2>
                        <p class="text-gray-500">
                            {{ customer?.email || customer?.phone }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a-button @click="handleRecordPayment" type="primary">
                            Record Payment
                        </a-button>
                        <a-button @click="handleAddTransaction"
                            >Add Transaction</a-button
                        >
                        <a-button @click="handleEditCreditLimit"
                            >Edit Credit Limit</a-button
                        >
                    </div>
                </div>

                <!-- Credit Summary -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600">Credit Limit</div>
                        <div class="text-2xl font-bold text-blue-600">
                            {{ formattedTotal(customer?.credit_limit || 0) }}
                        </div>
                    </div>
                    <div class="bg-red-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600">Current Balance</div>
                        <div class="text-2xl font-bold text-red-600">
                            {{ formattedTotal(customer?.credit_balance || 0) }}
                        </div>
                    </div>
                    <div class="bg-green-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600">
                            Available Credit
                        </div>
                        <div class="text-2xl font-bold text-green-600">
                            {{ formattedTotal(availableCredit || 0) }}
                        </div>
                    </div>
                    <div class="bg-orange-50 p-4 rounded-lg">
                        <div class="text-sm text-gray-600">Total Overdue</div>
                        <div class="text-2xl font-bold text-orange-600">
                            {{ formattedTotal(overdueAmount || 0) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Outstanding Invoices -->
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4">Outstanding Invoices</h3>
                <OutstandingInvoicesTable
                    :invoices="outstandingInvoices"
                    :customer="customer"
                    @record-payment="handleRecordPayment"
                />
            </div>

            <!-- Payment History -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h3 class="text-lg font-semibold mb-4">Payment History</h3>
                <PaymentHistoryTable :history="paymentHistory" />
            </div>
        </div>

        <!-- Modals -->
        <RecordPaymentModal
            :visible="showRecordPaymentModal"
            :customer="customer"
            :outstanding-invoices="outstandingInvoices"
            @close="handleModalClose"
            @saved="handleSaved"
        />

        <CreditTransactionModal
            :visible="showCreditTransactionModal"
            :customer="customer"
            @close="handleModalClose"
            @saved="handleSaved"
        />

        <CreditLimitModal
            :visible="showCreditLimitModal"
            :customer="customer"
            @close="handleModalClose"
            @saved="handleSaved"
        />
    </AuthenticatedLayout>
</template>
