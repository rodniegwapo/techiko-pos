<script setup>
import { computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useMediaQuery } from "@vueuse/core";
import dayjs from "dayjs";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { paymentMethodLabel } from "../paymentMethods";
import PrimaryButton from "@/Components/PrimaryButton.vue";

const props = defineProps({
    open: { type: Boolean, default: false },
    /** The template being edited, or null for a new one. */
    recurring: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
    canSeeAllStores: { type: Boolean, default: false },
    restrictedLocationId: { type: Number, default: null },
    activeLocationId: { type: Number, default: null },
});
const emit = defineEmits(["close"]);

const { getRoute } = useDomainRoutes();
const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() => (isMdUp.value ? 620 : "calc(100vw - 24px)"));
const modalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

const BUSINESS_WIDE = "business";
const WEEKDAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

const defaultStoreId = () => {
    const ids = (props.options.locations || []).map((l) => l.id);
    return ids.includes(props.activeLocationId) ? props.activeLocationId : ids[0] ?? null;
};

const form = useForm({
    expense_category_id: null,
    amount: null,
    description: "",
    payee: "",
    payment_method: "bank",
    location: null,
    frequency: "monthly",
    day_of_month: dayjs().date(),
    day_of_week: dayjs().day(),
    start_date: dayjs().format("YYYY-MM-DD"),
    end_date: null,
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        const r = props.recurring;
        form.expense_category_id = r?.expense_category_id ?? null;
        form.amount = r?.amount ?? null;
        form.description = r?.description ?? "";
        form.payee = r?.payee ?? "";
        form.payment_method = r?.payment_method ?? "bank";
        form.location = r ? r.location_id ?? BUSINESS_WIDE : props.restrictedLocationId ?? defaultStoreId() ?? BUSINESS_WIDE;
        form.frequency = r?.frequency ?? "monthly";
        form.day_of_month = r?.day_of_month ?? dayjs().date();
        form.day_of_week = r?.day_of_week ?? dayjs().day();
        form.start_date = r?.start_date ?? dayjs().format("YYYY-MM-DD");
        form.end_date = r?.end_date ?? null;
    },
);

const categoryOptions = computed(() =>
    (props.options.categories || [])
        .filter((c) => c.is_active || c.id === props.recurring?.expense_category_id)
        .map((c) => ({ value: c.id, label: c.name })),
);
const locationOptions = computed(() => [
    ...(props.canSeeAllStores ? [{ value: BUSINESS_WIDE, label: "Business-wide (no specific store)" }] : []),
    ...(props.options.locations || []).map((l) => ({ value: l.id, label: l.name })),
]);
const paymentOptions = computed(() =>
    (props.options.payment_methods || []).map((m) => ({ value: m, label: paymentMethodLabel(m) })),
);
const weekdayOptions = WEEKDAYS.map((label, value) => ({ value, label }));

const submit = () => {
    const isEdit = !!props.recurring;
    const url = isEdit
        ? getRoute("expenses.recurring.update", { recurring: props.recurring.id })
        : getRoute("expenses.recurring.store");

    form.transform(({ location, ...rest }) => ({
        ...rest,
        location_id: location === BUSINESS_WIDE ? null : location,
        day_of_month: rest.frequency === "monthly" ? rest.day_of_month : null,
        day_of_week: rest.frequency === "weekly" ? rest.day_of_week : null,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) {
                notification.error({ message: "Error", description: page.props.flash.error });
                return;
            }
            notification.success({ message: "Success", description: page.props.flash?.success });
            emit("close");
        },
    };

    isEdit ? form.put(url, options) : form.post(url, options);
};
</script>

<template>
    <a-modal
        :visible="open"
        :title="recurring ? 'Edit Recurring Expense' : 'Add Recurring Expense'"
        :width="modalWidth"
        :style="modalRootStyle"
        centered
        :mask-closable="false"
        @cancel="emit('close')"
    >
        <a-form layout="vertical">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                <a-form-item label="Amount" required :validate-status="form.errors.amount ? 'error' : ''" :help="form.errors.amount">
                    <a-input-number v-model:value="form.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" size="large" />
                </a-form-item>
                <a-form-item
                    label="Category"
                    required
                    :validate-status="form.errors.expense_category_id ? 'error' : ''"
                    :help="form.errors.expense_category_id"
                >
                    <a-select
                        v-model:value="form.expense_category_id"
                        :options="categoryOptions"
                        placeholder="Choose a category"
                        show-search
                        option-filter-prop="label"
                        size="large"
                    />
                </a-form-item>
            </div>

            <a-form-item label="Description" required :validate-status="form.errors.description ? 'error' : ''" :help="form.errors.description">
                <a-input v-model:value="form.description" placeholder="e.g. Shop rent, Internet, Security guard" size="large" />
            </a-form-item>

            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                <a-form-item label="Payee">
                    <a-input v-model:value="form.payee" placeholder="Who is paid (optional)" size="large" />
                </a-form-item>
                <a-form-item label="Store" :validate-status="form.errors.location_id ? 'error' : ''" :help="form.errors.location_id">
                    <a-select v-model:value="form.location" :options="locationOptions" :disabled="!canSeeAllStores" size="large" />
                </a-form-item>
                <a-form-item label="Paid from" required :validate-status="form.errors.payment_method ? 'error' : ''" :help="form.errors.payment_method">
                    <a-select v-model:value="form.payment_method" :options="paymentOptions" size="large" />
                </a-form-item>
                <a-form-item label="Repeats" required>
                    <a-radio-group v-model:value="form.frequency" button-style="solid" size="large">
                        <a-radio-button value="monthly">Monthly</a-radio-button>
                        <a-radio-button value="weekly">Weekly</a-radio-button>
                    </a-radio-group>
                </a-form-item>

                <a-form-item
                    v-if="form.frequency === 'monthly'"
                    label="Day of the month"
                    required
                    :validate-status="form.errors.day_of_month ? 'error' : ''"
                    :help="form.errors.day_of_month || (form.day_of_month > 28 ? 'Shorter months use their last day.' : '')"
                >
                    <a-input-number v-model:value="form.day_of_month" :min="1" :max="31" :precision="0" class="w-full" size="large" />
                </a-form-item>
                <a-form-item
                    v-else
                    label="Day of the week"
                    required
                    :validate-status="form.errors.day_of_week ? 'error' : ''"
                    :help="form.errors.day_of_week"
                >
                    <a-select v-model:value="form.day_of_week" :options="weekdayOptions" size="large" />
                </a-form-item>

                <a-form-item label="Starts" required :validate-status="form.errors.start_date ? 'error' : ''" :help="form.errors.start_date">
                    <a-date-picker v-model:value="form.start_date" value-format="YYYY-MM-DD" class="w-full" size="large" />
                </a-form-item>
                <a-form-item label="Ends (optional)" :validate-status="form.errors.end_date ? 'error' : ''" :help="form.errors.end_date">
                    <a-date-picker v-model:value="form.end_date" value-format="YYYY-MM-DD" class="w-full" size="large" />
                </a-form-item>
            </div>

            <a-alert
                type="info"
                show-icon
                message="Each run is added to Expenses automatically on its date. Runs dated before today are added as soon as you save."
            />
        </a-form>

        <template #footer>
            <a-button @click="emit('close')">Cancel</a-button>
            <primary-button :loading="form.processing" @click="submit">
                {{ recurring ? "Update" : "Submit" }}
            </primary-button>
        </template>
    </a-modal>
</template>
