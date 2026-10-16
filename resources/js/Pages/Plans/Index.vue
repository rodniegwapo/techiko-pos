<script setup>
import { computed, reactive, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { notification } from "ant-design-vue";
import { IconEdit, IconPlus } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { useFilters, toLabel } from "@/Composables/useFilters";

/**
 * Super admins: the plans organizations subscribe to, with the Free plan (organizations without a
 * plan) first. A new price counts from each organization's next payment; payments already recorded
 * keep their amounts. Plans are turned off, not deleted.
 */
const props = defineProps({
    tiers: { type: Array, default: () => [] },
    /** The Free plan's limits, its organizations and whether the filters keep it. */
    freePlan: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
    /** What a plan can include: [{ value, label }]. */
    featureOptions: { type: Array, default: () => [] },
    /** When false, organizations still get every feature except Finance, whatever their plan. */
    planGating: { type: Boolean, default: false },
    /** When false, no plan's product limit counts. */
    productLimitsOn: { type: Boolean, default: false },
});

const peso = (amount) => `₱${Number(amount ?? 0).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`;
const limit = (value) => (value == null ? "Unlimited" : Number(value).toLocaleString());
const days = (value) => (value == null ? "Unlimited" : `${Number(value).toLocaleString()} days`);
const featureLabel = (key) => props.featureOptions.find((o) => o.value === key)?.label ?? key;

/*** === FILTERS === ***/
const spinning = ref(false);
const search = ref(props.filters?.search ?? "");
const status = ref(props.filters?.status ?? null);
const billing = ref(props.filters?.billing ?? null);

const statusOptions = [
    { label: "Active", value: "active" },
    { label: "Off", value: "off" },
];
const billingOptions = [
    { label: "Shown on billing", value: "shown" },
    { label: "Hidden from billing", value: "hidden" },
];

const getItems = () => {
    router.reload({
        only: ["tiers", "freePlan", "filters"],
        data: {
            search: search.value || undefined,
            status: status.value || undefined,
            billing: billing.value || undefined,
        },
        preserveScroll: true,
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, getItems, { debounce: 300 });

const { filters: filterValues, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        { label: "Status", key: "status", ref: status, getLabel: toLabel(computed(() => statusOptions)) },
        { label: "Billing", key: "billing", ref: billing, getLabel: toLabel(computed(() => billingOptions)) },
    ],
});

const filtersConfig = [
    { key: "status", label: "Status", type: "select", options: statusOptions },
    { key: "billing", label: "Billing page", type: "select", options: billingOptions },
];

const clearFilters = () => Object.keys(filterValues.value).forEach((key) => (filterValues.value[key] = null));

/*** === TABLE === ***/
const columns = [
    { title: "Plan", key: "name" },
    { title: "Price / month", key: "amount", align: "right", width: 130 },
    { title: "AI credits / month", key: "ai_monthly_credits", align: "right", width: 150 },
    { title: "Users", key: "max_users", align: "right", width: 100 },
    { title: "Products", key: "max_products", align: "right", width: 110 },
    { title: "Sales history", key: "history_days", align: "right", width: 120 },
    { title: "Includes", key: "features", width: 260 },
    { title: "Organizations", key: "organizations", align: "right", width: 120 },
    { title: "", key: "actions", width: 80, align: "right" },
];

/** The Free plan first (when the filters keep it), then the paid plans. */
const rows = computed(() => {
    const free = props.freePlan?.matches_filters
        ? [
              {
                  id: "free",
                  is_free: true,
                  name: "Free",
                  slug: "free",
                  amount: 0,
                  ai_monthly_credits: props.freePlan.ai_monthly_credits,
                  max_users: props.freePlan.max_users,
                  max_products: props.freePlan.max_products,
                  history_days: props.freePlan.history_days,
                  features: [],
                  organizations: props.freePlan.organizations,
                  is_active: true,
                  shown_on_billing: true,
              },
          ]
        : [];
    return [...free, ...props.tiers];
});

/*** === PAID PLAN FORM === ***/
const blank = () => ({
    name: "",
    amount: 0,
    ai_monthly_credits: null,
    features: [],
    max_products: null,
    max_users: null,
    is_active: true,
    shown_on_billing: true,
    sort_order: (props.tiers.at(-1)?.sort_order ?? 0) + 1,
});

const form = reactive({ open: false, saving: false, editing: null, errors: {}, data: blank() });

const openAdd = () => Object.assign(form, { open: true, editing: null, errors: {}, data: blank() });
const openEdit = (tier) =>
    Object.assign(form, {
        open: true,
        editing: tier,
        errors: {},
        data: {
            name: tier.name,
            amount: tier.amount,
            ai_monthly_credits: tier.ai_monthly_credits,
            features: [...(tier.features ?? [])],
            max_products: tier.max_products,
            max_users: tier.max_users,
            is_active: tier.is_active,
            shown_on_billing: tier.shown_on_billing,
            sort_order: tier.sort_order,
        },
    });

const priceChanged = computed(() => form.editing && Number(form.editing.amount) !== Number(form.data.amount));
const okProps = computed(() => ({ disabled: !form.data.name.trim() }));

const save = () => {
    form.saving = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.open = false;
            notification.success({ message: form.editing ? "Plan saved" : "Plan added" });
        },
        onError: (errors) => (form.errors = errors),
        onFinish: () => (form.saving = false),
    };
    if (form.editing) {
        router.put(route("service-tiers.update", form.editing.id), form.data, options);
    } else {
        router.post(route("service-tiers.store"), form.data, options);
    }
};

/*** === FREE PLAN FORM === ***/
const freeForm = reactive({ open: false, saving: false, errors: {}, data: {} });

const openFree = () =>
    Object.assign(freeForm, {
        open: true,
        errors: {},
        data: {
            ai_monthly_credits: props.freePlan?.ai_monthly_credits ?? 0,
            max_users: props.freePlan?.max_users ?? null,
            max_products: props.freePlan?.max_products ?? null,
            history_days: props.freePlan?.history_days ?? null,
        },
    });

const freeOkProps = computed(() => ({ disabled: freeForm.data.ai_monthly_credits == null }));

const saveFree = () => {
    freeForm.saving = true;
    router.put(route("service-tiers.free.update"), freeForm.data, {
        preserveScroll: true,
        onSuccess: () => {
            freeForm.open = false;
            notification.success({ message: "Free plan saved" });
        },
        onError: (errors) => (freeForm.errors = errors),
        onFinish: () => (freeForm.saving = false),
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Plans" />
        <ContentHeader class="mb-6" title="Plans">
            <template #meta>
                What organizations pay each month and what each plan includes. A new price counts from each organization's next payment.
            </template>
        </ContentHeader>

        <ContentLayout title="Plans">
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search plans by name"
                    allow-clear
                    class="min-w-[100px] max-w-[300px]"
                    data-testid="plans-search"
                />
                <a-button type="primary" data-testid="add-plan" @click="openAdd">
                    <span class="inline-flex items-center gap-1"><IconPlus :size="16" /> Add plan</span>
                </a-button>
                <FilterDropdown v-model="filterValues" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearFilters" />
            </template>

            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="rows"
                    :loading="spinning"
                    :pagination="false"
                    row-key="id"
                    size="middle"
                    :scroll="{ x: 1230 }"
                    data-testid="plans-table"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'name'">
                            <span class="font-medium text-gray-900" :data-testid="record.is_free ? 'free-plan-row' : undefined">{{ record.name }}</span>
                            <span class="ml-1 text-xs text-gray-400">{{ record.slug }}</span>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <a-tag v-if="record.is_free" color="green" class="!m-0">No plan</a-tag>
                                <a-tag v-if="!record.is_active" color="red" class="!m-0">Off</a-tag>
                                <a-tag v-if="!record.shown_on_billing" class="!m-0">Hidden from billing</a-tag>
                            </div>
                        </template>
                        <template v-else-if="column.key === 'amount'">
                            <span class="tabular-nums">{{ peso(record.amount) }}</span>
                        </template>
                        <template v-else-if="column.key === 'max_users'">
                            <span class="tabular-nums">{{ limit(record.max_users) }}</span>
                            <div v-if="record.is_free && !planGating" class="text-xs text-gray-400">not enforced yet</div>
                        </template>
                        <template v-else-if="column.key === 'max_products'">
                            <span class="tabular-nums" :class="{ 'text-gray-400': !productLimitsOn }">{{ limit(record.max_products) }}</span>
                        </template>
                        <template v-else-if="column.key === 'history_days'">
                            <span class="tabular-nums">{{ days(record.history_days) }}</span>
                        </template>
                        <template v-else-if="column.key === 'ai_monthly_credits'">
                            <span class="tabular-nums">{{ limit(record.ai_monthly_credits) }}</span>
                        </template>
                        <template v-else-if="column.key === 'features'">
                            <div class="flex flex-wrap gap-1" data-testid="plan-features">
                                <a-tag v-for="key in record.features" :key="key" color="blue" class="!m-0">{{ featureLabel(key) }}</a-tag>
                                <span v-if="!record.features.length" class="text-xs text-gray-400">
                                    {{ record.is_free ? "Selling, products, customers, stock and the AI assistant" : "Free-plan features only" }}
                                </span>
                            </div>
                        </template>
                        <template v-else-if="column.key === 'organizations'">
                            <span class="tabular-nums">{{ record.organizations }}</span>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <IconTooltipButton
                                :name="record.is_free ? 'Edit Free plan' : `Edit ${record.name}`"
                                hover="hover:bg-blue-500"
                                @click="record.is_free ? openFree() : openEdit(record)"
                            >
                                <IconEdit :size="20" class="mx-auto" />
                            </IconTooltipButton>
                        </template>
                    </template>
                </a-table>
                <p v-if="!productLimitsOn" class="px-6 pt-3 text-xs text-gray-400">
                    Product limits don't count right now (FEATURE_UNLIMITED_PRODUCTS is on).
                </p>
            </template>
        </ContentLayout>

        <!-- Paid plan -->
        <a-modal
            v-model:visible="form.open"
            :title="form.editing ? `Edit ${form.editing.name}` : 'Add plan'"
            :ok-text="form.editing ? 'Save plan' : 'Add plan'"
            :confirm-loading="form.saving"
            :ok-button-props="okProps"
            @ok="save"
        >
            <div class="space-y-3" data-testid="plan-form">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Name</label>
                    <a-input v-model:value="form.data.name" :maxlength="60" placeholder="e.g. Pro servicing" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Price per month (₱)</label>
                        <!-- No `prefix` on a-input-number: it freezes the page in this Ant Design version. -->
                        <a-input-number v-model:value="form.data.amount" :min="0" :precision="2" class="!w-full" data-testid="plan-amount" />
                        <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">AI credits / month</label>
                        <a-input-number v-model:value="form.data.ai_monthly_credits" :min="0" :precision="0" class="!w-full" placeholder="Unlimited" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Max products</label>
                        <a-input-number v-model:value="form.data.max_products" :min="1" :precision="0" class="!w-full" placeholder="Unlimited" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Max users</label>
                        <a-input-number v-model:value="form.data.max_users" :min="1" :precision="0" class="!w-full" placeholder="Unlimited" />
                    </div>
                </div>
                <p class="-mt-1 text-xs text-gray-500">Leave a limit blank for unlimited.</p>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Includes</label>
                    <a-checkbox-group v-model:value="form.data.features" class="!grid grid-cols-1 gap-1" data-testid="plan-feature-options">
                        <a-checkbox v-for="option in featureOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </a-checkbox>
                    </a-checkbox-group>
                    <p class="mt-1 text-xs text-gray-500">
                        Everything else (selling, products, discounts, customers, loyalty, credit, cash, basic stock and the AI assistant) is free.
                        <template v-if="!planGating">Plans aren't enforced yet (FEATURE_PLAN_GATING is off): every organization keeps everything, and Finance is still turned on per organization.</template>
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <a-switch v-model:checked="form.data.is_active" size="small" /> Active
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <a-switch v-model:checked="form.data.shown_on_billing" size="small" /> Show on the billing page
                    </label>
                </div>
                <div class="w-32">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Order</label>
                    <a-input-number v-model:value="form.data.sort_order" :min="0" :precision="0" class="!w-full" />
                </div>

                <a-alert
                    v-if="priceChanged"
                    type="info"
                    show-icon
                    :message="`Organizations on this plan (${form.editing.organizations}) pay ${peso(form.data.amount)} from their next payment. Payments already recorded keep their amounts.`"
                />
            </div>
        </a-modal>

        <!-- Free plan -->
        <a-modal
            v-model:visible="freeForm.open"
            title="Edit Free plan"
            ok-text="Save Free plan"
            :confirm-loading="freeForm.saving"
            :ok-button-props="freeOkProps"
            @ok="saveFree"
        >
            <div class="space-y-3" data-testid="free-plan-form">
                <p class="text-sm text-gray-600">
                    For organizations without a plan ({{ freePlan?.organizations ?? 0 }} now). Free costs nothing and includes no paid
                    features; these are its limits.
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">AI credits / month</label>
                        <a-input-number v-model:value="freeForm.data.ai_monthly_credits" :min="0" :precision="0" class="!w-full" data-testid="free-ai-credits" />
                        <p v-if="freeForm.errors.ai_monthly_credits" class="mt-1 text-xs text-red-600">{{ freeForm.errors.ai_monthly_credits }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Max users</label>
                        <a-input-number v-model:value="freeForm.data.max_users" :min="1" :precision="0" class="!w-full" placeholder="Unlimited" data-testid="free-max-users" />
                        <p v-if="freeForm.errors.max_users" class="mt-1 text-xs text-red-600">{{ freeForm.errors.max_users }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Max products</label>
                        <a-input-number v-model:value="freeForm.data.max_products" :min="1" :precision="0" class="!w-full" placeholder="Unlimited" />
                        <p v-if="freeForm.errors.max_products" class="mt-1 text-xs text-red-600">{{ freeForm.errors.max_products }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Sales history (days)</label>
                        <a-input-number v-model:value="freeForm.data.history_days" :min="1" :max="3650" :precision="0" class="!w-full" placeholder="Unlimited" data-testid="free-history-days" />
                        <p v-if="freeForm.errors.history_days" class="mt-1 text-xs text-red-600">{{ freeForm.errors.history_days }}</p>
                    </div>
                </div>
                <p class="-mt-1 text-xs text-gray-500">
                    Leave a limit blank for unlimited. Sales history also applies to paid plans without full sales history.
                    <template v-if="!planGating">The user and history limits apply once plans are enforced (FEATURE_PLAN_GATING).</template>
                    <template v-if="!productLimitsOn">The product limit applies only while FEATURE_UNLIMITED_PRODUCTS is off.</template>
                </p>
            </div>
        </a-modal>
    </AuthenticatedLayout>
</template>
