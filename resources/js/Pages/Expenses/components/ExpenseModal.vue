<script setup>
import { computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useMediaQuery } from "@vueuse/core";
import dayjs from "dayjs";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { validationMessage } from "@/Composables/useValidationMessage.js";
import { paymentMethodLabel } from "../paymentMethods";
import PrimaryButton from "@/Components/PrimaryButton.vue";

const props = defineProps({
    open: { type: Boolean, default: false },
    /** The expense being edited, or null to record a new one. */
    expense: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
    canSeeAllStores: { type: Boolean, default: false },
    restrictedLocationId: { type: Number, default: null },
    activeLocationId: { type: Number, default: null },
});
const emit = defineEmits(["close", "manage-categories"]);

const { getRoute } = useDomainRoutes();

const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() => (isMdUp.value ? 620 : "calc(100vw - 24px)"));
const modalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

/** a-select can't hold null as a real option, so "business-wide" travels as this value until submit. */
const BUSINESS_WIDE = "business";

/** The store picked in the header, if it is one this user can file expenses for. */
const defaultStoreId = () => {
    const ids = (props.options.locations || []).map((l) => l.id);
    return ids.includes(props.activeLocationId) ? props.activeLocationId : ids[0] ?? null;
};

const form = useForm({
    expense_category_id: null,
    amount: null,
    expense_date: dayjs().format("YYYY-MM-DD"),
    description: "",
    payee: "",
    payment_method: "cash_register",
    location: null,
    reference_no: "",
    notes: "",
    receipt: null,
    remove_receipt: false,
    repeat: "none",
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        const e = props.expense;
        form.expense_category_id = e?.expense_category_id ?? null;
        form.amount = e?.amount ?? null;
        form.expense_date = e?.expense_date ?? dayjs().format("YYYY-MM-DD");
        form.description = e?.description ?? "";
        form.payee = e?.payee ?? "";
        form.payment_method = e?.payment_method ?? "cash_register";
        form.location = e
            ? e.location_id ?? BUSINESS_WIDE
            : props.restrictedLocationId ?? defaultStoreId() ?? BUSINESS_WIDE;
        form.reference_no = e?.reference_no ?? "";
        form.notes = e?.notes ?? "";
        form.receipt = null;
        form.remove_receipt = false;
        form.repeat = "none";
    },
);

const categoryOptions = computed(() =>
    (props.options.categories || [])
        // Keep an inactive category selectable only on an expense that already uses it.
        .filter((c) => c.is_active || c.id === props.expense?.expense_category_id)
        .map((c) => ({ value: c.id, label: c.name })),
);

const locationOptions = computed(() => [
    ...(props.canSeeAllStores ? [{ value: BUSINESS_WIDE, label: "Business-wide (no specific store)" }] : []),
    ...(props.options.locations || []).map((l) => ({ value: l.id, label: l.name })),
]);

const paymentOptions = computed(() =>
    (props.options.payment_methods || []).map((m) => ({ value: m, label: paymentMethodLabel(m) })),
);

const paidFromRegister = computed(() => form.payment_method === "cash_register");

const beforeReceiptUpload = (file) => {
    form.receipt = file;
    form.remove_receipt = false;
    return false; // keep the file for the form instead of uploading straight away
};

const submit = () => {
    const isEdit = !!props.expense;
    const url = isEdit
        ? getRoute("expenses.update", { expense: props.expense.id })
        : getRoute("expenses.store");

    form
        .transform((data) => {
            const { location, ...rest } = data;
            return {
                ...rest,
                location_id: location === BUSINESS_WIDE ? null : location,
                // Multipart forms can only be POSTed; Laravel reads the real verb from _method.
                ...(isEdit ? { _method: "put" } : {}),
            };
        })
        .post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) {
                    notification.error({ message: "Error", description: page.props.flash.error });
                    return;
                }
                notification.success({ message: "Success", description: page.props.flash?.success || (isEdit ? "Expense updated." : "Expense saved.") });
                emit("close");
            },
        });
};
</script>

<template>
    <a-modal
        :visible="open"
        :title="expense ? 'Edit Expense' : 'Record Expense'"
        :width="modalWidth"
        :style="modalRootStyle"
        centered
        :mask-closable="false"
        @cancel="emit('close')"
    >
        <a-form layout="vertical" data-testid="expense-form">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                <a-form-item
                    label="Amount"
                    required
                    :validate-status="form.errors.amount ? 'error' : ''"
                    :help="form.errors.amount"
                >
                    <a-input-number
                        v-model:value="form.amount"
                        :min="0.01"
                        :precision="2"
                        prefix="₱"
                        class="w-full"
                        size="large"
                        data-testid="expense-amount"
                    />
                </a-form-item>

                <a-form-item
                    label="Date"
                    required
                    :validate-status="form.errors.expense_date ? 'error' : ''"
                    :help="form.errors.expense_date"
                >
                    <a-date-picker
                        v-model:value="form.expense_date"
                        value-format="YYYY-MM-DD"
                        :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')"
                        class="w-full"
                        size="large"
                    />
                </a-form-item>
            </div>

            <a-form-item
                label="Description"
                required
                :validate-status="form.errors.description ? 'error' : ''"
                :help="form.errors.description"
            >
                <a-input
                    v-model:value="form.description"
                    placeholder="e.g. October rent, Meralco bill"
                    size="large"
                    data-testid="expense-description"
                />
            </a-form-item>

            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                <a-form-item
                    required
                    :validate-status="form.errors.expense_category_id ? 'error' : ''"
                    :help="form.errors.expense_category_id"
                >
                    <template #label>
                        <span>Category</span>
                        <a class="ml-2 text-xs" @click.prevent="emit('manage-categories')">Manage</a>
                    </template>
                    <a-select
                        v-model:value="form.expense_category_id"
                        :options="categoryOptions"
                        placeholder="Choose a category"
                        show-search
                        option-filter-prop="label"
                        size="large"
                        data-testid="expense-category"
                    />
                </a-form-item>

                <a-form-item label="Payee" :validate-status="form.errors.payee ? 'error' : ''" :help="form.errors.payee">
                    <a-input v-model:value="form.payee" placeholder="Who was paid (optional)" size="large" />
                </a-form-item>

                <a-form-item
                    label="Store"
                    :validate-status="form.errors.location_id ? 'error' : ''"
                    :help="form.errors.location_id"
                >
                    <a-select
                        v-model:value="form.location"
                        :options="locationOptions"
                        :disabled="!canSeeAllStores"
                        size="large"
                        data-testid="expense-location"
                    />
                </a-form-item>

                <a-form-item
                    label="Paid from"
                    required
                    :validate-status="form.errors.payment_method ? 'error' : ''"
                    :help="form.errors.payment_method"
                >
                    <a-select
                        v-model:value="form.payment_method"
                        :options="paymentOptions"
                        size="large"
                        data-testid="expense-payment-method"
                    />
                </a-form-item>
            </div>

            <a-alert
                v-if="paidFromRegister"
                type="info"
                show-icon
                class="mb-4"
                message="This will also be recorded as cash out in the store's Wallet cash ledger, so the drawer count stays correct."
            />

            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                <a-form-item
                    label="Reference no."
                    :validate-status="form.errors.reference_no ? 'error' : ''"
                    :help="form.errors.reference_no"
                >
                    <a-input v-model:value="form.reference_no" placeholder="OR / invoice no. (optional)" size="large" />
                </a-form-item>

                <a-form-item
                    label="Receipt"
                    :validate-status="form.errors.receipt ? 'error' : ''"
                    :help="form.errors.receipt"
                >
                    <a-upload
                        :before-upload="beforeReceiptUpload"
                        :show-upload-list="false"
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                    >
                        <a-button size="large">{{ form.receipt ? "Change file" : "Attach photo or PDF" }}</a-button>
                    </a-upload>
                    <div v-if="form.receipt" class="mt-1 truncate text-xs text-gray-600">{{ form.receipt.name }}</div>
                    <div v-else-if="expense?.has_receipt" class="mt-1 text-xs">
                        <template v-if="!form.remove_receipt">
                            <a :href="getRoute('expenses.receipt', { expense: expense.id })" target="_blank" rel="noopener">
                                View current receipt
                            </a>
                            · <a class="text-red-500" @click.prevent="form.remove_receipt = true">Remove</a>
                        </template>
                        <span v-else class="text-gray-500">
                            Receipt will be removed. <a @click.prevent="form.remove_receipt = false">Undo</a>
                        </span>
                    </div>
                </a-form-item>
            </div>

            <a-form-item v-if="!expense" label="Repeat">
                <a-radio-group v-model:value="form.repeat" data-testid="expense-repeat">
                    <a-radio value="none">Just once</a-radio>
                    <a-radio value="monthly">Every month on this date</a-radio>
                    <a-radio value="weekly">Every week on this day</a-radio>
                </a-radio-group>
            </a-form-item>

            <a-form-item label="Notes" :validate-status="form.errors.notes ? 'error' : ''" :help="form.errors.notes">
                <a-textarea v-model:value="form.notes" :rows="2" placeholder="Optional" />
            </a-form-item>
        </a-form>

        <template #footer>
            <a-button @click="emit('close')">Cancel</a-button>
            <primary-button :loading="form.processing" data-testid="expense-submit" @click="submit">
                {{ expense ? "Update" : "Submit" }}
            </primary-button>
        </template>
    </a-modal>
</template>
