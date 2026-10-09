<template>
    <a-modal
        :visible="visible"
        title="Add Credit Transaction"
        :confirm-loading="loading"
        @ok="handleSubmit"
        @cancel="handleCancel"
        width="640px"
    >
        <a-form :model="formData" layout="vertical" ref="formRef">
            <!-- Transaction Type -->
            <a-form-item
                label="Transaction Type"
                name="transaction_type"
                :rules="[
                    {
                        required: true,
                        message: 'Please select transaction type',
                    },
                ]"
            >
                <a-select
                    :value="formData.transaction_type"
                    @change="(val) => (formData.transaction_type = val)"
                    placeholder="Select transaction type"
                >
                    <a-select-option v-if="canCharge" value="credit"
                        >Charge (manual credit)</a-select-option
                    >
                    <a-select-option value="adjustment"
                        >Adjustment</a-select-option
                    >
                    <a-select-option value="refund">Refund</a-select-option>
                </a-select>
            </a-form-item>

            <!-- Amount -->
            <a-form-item
                label="Amount"
                name="amount"
                :rules="[{ required: true, message: 'Please enter amount' }]"
            >
                <a-input-number
                    :value="formData.amount"
                    @change="(val) => (formData.amount = val)"
                    :min="isAdjustment ? undefined : 0.01"
                    :max="isCharge ? availableCredit : undefined"
                    :precision="2"
                    :step="100"
                    style="width: 100%"
                    :placeholder="
                        isAdjustment
                            ? 'Enter amount (positive to increase, negative to decrease)'
                            : 'Enter amount'
                    "
                />
                <div v-if="isAdjustment" class="text-sm text-gray-500 mt-1">
                    Use a positive value to increase the balance, or a negative
                    one (e.g. -500) to decrease it
                </div>
                <div v-else-if="isCharge" class="text-sm text-gray-500 mt-1">
                    Available credit: {{ peso(availableCredit) }}
                </div>
            </a-form-item>

            <template v-if="isCharge">
                <a-form-item name="use_installments" class="mb-3">
                    <a-switch
                        v-model:checked="formData.use_installments"
                        size="small"
                        aria-label="Pay in installments"
                    />
                    <span
                        class="ml-2 cursor-pointer select-none"
                        @click="formData.use_installments = !formData.use_installments"
                        >Pay in installments</span
                    >
                </a-form-item>

                <!-- Single due date -->
                <a-form-item
                    v-if="!formData.use_installments"
                    label="Due Date"
                    name="due_date"
                    :rules="[{ required: true, message: 'Please pick a due date' }]"
                >
                    <a-date-picker
                        v-model:value="formData.due_date"
                        class="w-full"
                        format="MMM D, YYYY"
                        value-format="YYYY-MM-DD"
                        :disabled-date="beforeToday"
                    />
                </a-form-item>

                <!-- Installment schedule -->
                <template v-else>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-3">
                        <a-form-item label="Installments">
                            <a-input-number
                                v-model:value="schedule.count"
                                :min="2"
                                :max="60"
                                :precision="0"
                                style="width: 100%"
                            />
                        </a-form-item>
                        <a-form-item label="Every">
                            <a-select v-model:value="schedule.frequency">
                                <a-select-option value="week"
                                    >Week</a-select-option
                                >
                                <a-select-option value="15days"
                                    >15 days</a-select-option
                                >
                                <a-select-option value="month"
                                    >Month</a-select-option
                                >
                            </a-select>
                        </a-form-item>
                        <a-form-item label="First due">
                            <a-date-picker
                                v-model:value="schedule.first_due"
                                class="w-full"
                                format="MMM D, YYYY"
                                value-format="YYYY-MM-DD"
                                :disabled-date="beforeToday"
                            />
                        </a-form-item>
                    </div>

                    <a-table
                        :columns="installmentColumns"
                        :data-source="formData.installments"
                        :pagination="false"
                        size="small"
                        row-key="seq"
                        class="mb-2"
                        :scroll="{ y: 240 }"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'due_date'">
                                <a-date-picker
                                    v-model:value="record.due_date"
                                    size="small"
                                    class="w-full"
                                    format="MMM D, YYYY"
                                    value-format="YYYY-MM-DD"
                                    :disabled-date="beforeToday"
                                />
                            </template>
                            <template v-else-if="column.key === 'amount'">
                                <a-input-number
                                    v-model:value="record.amount"
                                    size="small"
                                    :min="0.01"
                                    :precision="2"
                                    style="width: 100%"
                                />
                            </template>
                        </template>
                    </a-table>
                    <div
                        class="text-sm mb-4"
                        :class="scheduleMatches ? 'text-gray-500' : 'text-red-600'"
                    >
                        Schedule total {{ peso(scheduleTotal) }} of
                        {{ peso(formData.amount || 0) }}
                        <span v-if="!scheduleMatches">
                            — adjust the amounts so they add up</span
                        >
                    </div>
                </template>
            </template>

            <!-- Reference Number -->
            <a-form-item label="Reference Number" name="reference_number">
                <a-input
                    v-model:value="formData.reference_number"
                    placeholder="Enter reference number (optional)"
                />
            </a-form-item>

            <!-- Notes -->
            <a-form-item label="Notes" name="notes">
                <a-textarea
                    v-model:value="formData.notes"
                    :rows="3"
                    placeholder="Enter notes (optional)"
                />
            </a-form-item>
        </a-form>
    </a-modal>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { useCredit } from "@/Composables/useCredit";

// Props
const props = defineProps({
    visible: Boolean,
    customer: Object,
    availableCredit: { type: Number, default: 0 },
    canCharge: { type: Boolean, default: false },
});

// Emits
const emit = defineEmits(["close", "saved"]);

// Composable
const { recordPayment, loading } = useCredit();

// Form reference
const formRef = ref(null);

const blankForm = () => ({
    transaction_type: props.canCharge ? "credit" : "adjustment",
    amount: null,
    due_date: null,
    use_installments: false,
    installments: [],
    reference_number: "",
    notes: "",
});

// Form data
const formData = ref(blankForm());

// How the installment schedule is generated; the rows stay editable afterwards.
const schedule = ref({ count: 3, frequency: "month", first_due: null });

const isCharge = computed(() => formData.value.transaction_type === "credit");
const isAdjustment = computed(
    () => formData.value.transaction_type === "adjustment"
);

const installmentColumns = [
    { title: "#", dataIndex: "seq", key: "seq", width: 48 },
    { title: "Due date", key: "due_date" },
    { title: "Amount", key: "amount", width: 160 },
];

const toCents = (v) => Math.round(Number(v || 0) * 100);
const peso = (v) =>
    `₱${Number(v || 0).toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const beforeToday = (d) => d && d.isBefore(dayjs().startOf("day"));

const scheduleTotal = computed(
    () =>
        formData.value.installments.reduce((s, i) => s + toCents(i.amount), 0) /
        100
);
const scheduleMatches = computed(
    () => toCents(scheduleTotal.value) === toCents(formData.value.amount)
);

// Even parts, with the last one taking whatever centavos are left over.
const buildSchedule = () => {
    const count = Math.max(2, Number(schedule.value.count) || 2);
    const total = toCents(formData.value.amount);
    const first = schedule.value.first_due
        ? dayjs(schedule.value.first_due)
        : dayjs().add(1, "month");
    const part = Math.floor(total / count);

    formData.value.installments = Array.from({ length: count }, (_, i) => {
        const due =
            schedule.value.frequency === "week"
                ? first.add(i, "week")
                : schedule.value.frequency === "15days"
                  ? first.add(i * 15, "day")
                  : first.add(i, "month");
        const cents = i === count - 1 ? total - part * (count - 1) : part;
        return {
            seq: i + 1,
            due_date: due.format("YYYY-MM-DD"),
            amount: cents / 100,
        };
    });
};

watch(
    () => [
        formData.value.use_installments,
        formData.value.amount,
        schedule.value.count,
        schedule.value.frequency,
        schedule.value.first_due,
    ],
    () => {
        if (formData.value.use_installments) buildSchedule();
    }
);

// Reset form when modal opens
watch(
    () => props.visible,
    (visible) => {
        if (visible) {
            formData.value = blankForm();
            schedule.value = {
                count: 3,
                frequency: "month",
                first_due: dayjs().add(1, "month").format("YYYY-MM-DD"),
            };
        }
    }
);

const SUCCESS = {
    credit: "Credit charge added",
    adjustment: "Adjustment recorded",
    refund: "Refund recorded",
};

// Submit handler
const handleSubmit = async () => {
    try {
        await formRef.value.validate();

        if (isCharge.value && formData.value.use_installments) {
            if (!scheduleMatches.value) {
                notification.error({
                    message: "Installments don't add up",
                    description: `The schedule totals ${peso(scheduleTotal.value)} but the charge is ${peso(formData.value.amount)}.`,
                });
                return;
            }
        }

        const payload = {
            transaction_type: formData.value.transaction_type,
            amount: formData.value.amount,
            reference_number: formData.value.reference_number || undefined,
            notes: formData.value.notes || undefined,
        };

        if (isCharge.value) {
            if (formData.value.use_installments) {
                payload.installments = formData.value.installments.map(
                    ({ due_date, amount }) => ({ due_date, amount })
                );
            } else {
                payload.due_date = formData.value.due_date;
            }
        }

        await recordPayment(props.customer.id, payload, {
            successMessage: SUCCESS[payload.transaction_type],
        });
        emit("saved");
    } catch (error) {
        // Error handled in composable
    }
};

// Cancel handler
const handleCancel = () => {
    emit("close");
};
</script>
