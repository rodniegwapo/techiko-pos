<script setup>
import { computed, reactive } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { IconEdit, IconPlus } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

/**
 * Super admins: the plans organizations subscribe to. A new price counts from each organization's
 * next payment; payments already recorded keep their amounts. Plans are turned off, not deleted.
 */
const props = defineProps({
    tiers: { type: Array, default: () => [] },
    /** The plan whose static QR (on the Servicing page) has its amount built in. */
    basicSlug: { type: String, default: "basic" },
    /** What a plan can include: [{ value, label }]. */
    featureOptions: { type: Array, default: () => [] },
    /** When false, organizations still get every feature except Finance and AI, whatever their plan. */
    planGating: { type: Boolean, default: false },
});

const peso = (amount) => `₱${Number(amount ?? 0).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`;
const limit = (value) => (value == null ? "Unlimited" : Number(value).toLocaleString());
const featureLabel = (key) => props.featureOptions.find((o) => o.value === key)?.label ?? key;

const columns = [
    { title: "Plan", key: "name" },
    { title: "Price / month", key: "amount", align: "right", width: 140 },
    { title: "AI credits / month", key: "ai_monthly_credits", align: "right", width: 150 },
    { title: "Products", key: "max_products", align: "right", width: 110 },
    { title: "Users", key: "max_users", align: "right", width: 100 },
    { title: "Includes", key: "features", width: 260 },
    { title: "Organizations", key: "organizations", align: "right", width: 120 },
    { title: "", key: "actions", width: 80, align: "right" },
];

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
const isBasic = computed(() => form.editing?.slug === props.basicSlug);
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
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Plans" />
        <ContentHeader class="mb-6" title="Plans">
            <template #actions>
                <a-button type="primary" data-testid="add-plan" @click="openAdd">
                    <span class="inline-flex items-center gap-1"><IconPlus :size="16" /> Add plan</span>
                </a-button>
            </template>
            <template #meta>
                What organizations pay each month and what each plan includes. A new price counts from each organization's next payment.
            </template>
        </ContentHeader>

        <section class="rounded-lg bg-white p-4 shadow">
            <a-table :columns="columns" :data-source="tiers" :pagination="false" row-key="id" size="middle" :scroll="{ x: 1120 }" data-testid="plans-table">
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'name'">
                        <span class="font-medium text-gray-900">{{ record.name }}</span>
                        <span class="ml-1 text-xs text-gray-400">{{ record.slug }}</span>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <a-tag v-if="!record.is_active" color="red" class="!m-0">Off</a-tag>
                            <a-tag v-if="!record.shown_on_billing" class="!m-0">Hidden from billing</a-tag>
                        </div>
                    </template>
                    <template v-else-if="column.key === 'amount'">
                        <span class="tabular-nums">{{ peso(record.amount) }}</span>
                    </template>
                    <template v-else-if="['ai_monthly_credits', 'max_products', 'max_users'].includes(column.key)">
                        <span class="tabular-nums">{{ limit(record[column.key]) }}</span>
                    </template>
                    <template v-else-if="column.key === 'features'">
                        <div class="flex flex-wrap gap-1" data-testid="plan-features">
                            <a-tag v-for="key in record.features" :key="key" color="blue" class="!m-0">{{ featureLabel(key) }}</a-tag>
                            <span v-if="!record.features.length" class="text-xs text-gray-400">Free-plan features only</span>
                        </div>
                    </template>
                    <template v-else-if="column.key === 'organizations'">
                        <span class="tabular-nums">{{ record.organizations }}</span>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <a-button size="small" @click="openEdit(record)">
                            <span class="inline-flex items-center gap-1"><IconEdit :size="14" /> Edit</span>
                        </a-button>
                    </template>
                </template>
            </a-table>
        </section>

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
                        Everything else (selling, products, discounts, customers, loyalty, credit, cash and basic stock) is free.
                        <template v-if="!planGating">Plans aren't enforced yet (FEATURE_PLAN_GATING is off): every organization keeps everything, and Finance and AI are still turned on per organization.</template>
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
                <a-alert
                    v-if="isBasic && priceChanged"
                    type="warning"
                    show-icon
                    message="Basic's static QR on the Servicing page has its amount built in. Replace that QR image if you change this price."
                />
            </div>
        </a-modal>
    </AuthenticatedLayout>
</template>
