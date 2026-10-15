<script setup>
import { computed, reactive } from "vue";
import { router, Head, usePage } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import dayjs from "dayjs";
import {
    IconEdit,
    IconArrowLeft,
    IconMapPin,
    IconUsers,
    IconReportAnalytics,
    IconSparkles,
} from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    /** Plan & AI credits (super admins only, otherwise null). */
    billing: {
        type: Object,
        default: null,
    },
});

const page = usePage();

// The domain arrives as a resource ({ data: … }).
const domain = computed(() => props.domain?.data ?? props.domain);
const isSuperUser = computed(() => !!page.props.auth?.user?.data?.is_super_user);

const formatDate = (dateString) => {
    if (!dateString) return "-";
    return new Date(dateString).toLocaleDateString("en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
};

const regional = computed(() => [
    { label: "Country", value: domain.value.country_code },
    { label: "Currency", value: domain.value.currency_code },
    { label: "Timezone", value: domain.value.timezone },
    { label: "Date format", value: domain.value.date_format },
    { label: "Time format", value: domain.value.time_format },
    { label: "Language", value: domain.value.language_code },
]);

/*** === FEATURES (super admins turn paid features on per organization) === ***/
const savedFeatures = () => ({
    finance: domain.value.settings?.features?.finance === true,
    ai: domain.value.settings?.features?.ai === true,
});

const features = reactive({ ...savedFeatures(), saving: false });

const featuresChanged = computed(() => {
    const saved = savedFeatures();
    return saved.finance !== features.finance || saved.ai !== features.ai;
});

const saveFeatures = () => {
    features.saving = true;
    router.patch(
        route("domains.features.update", { domain: domain.value.name_slug }),
        { finance: features.finance, ai: features.ai },
        {
            preserveScroll: true,
            onSuccess: () => {
                Object.assign(features, savedFeatures());
                notification.success({ message: "Features updated" });
            },
            onError: () => notification.error({ message: "Couldn't save the features" }),
            onFinish: () => (features.saving = false),
        },
    );
};

/*** === PLAN & AI CREDITS (super admins; the cost is never shown to the organization) === ***/
const savedPlan = () => ({
    service_tier_id: props.billing?.service_tier_id ?? null,
    ai_monthly_credits_override: props.billing?.ai_monthly_credits_override ?? null,
    subscription_started_at: props.billing?.subscription_started_at ?? null,
    subscription_ends_at: props.billing?.subscription_ends_at ?? null,
});

const plan = reactive({ ...savedPlan(), saving: false });

const planChanged = computed(() => {
    const saved = savedPlan();
    return ["service_tier_id", "ai_monthly_credits_override", "subscription_started_at", "subscription_ends_at"].some(
        (key) => saved[key] !== plan[key],
    );
});

/** "Ends in 5 days", "Ends today", "Ended 3 days ago" for the end date picked. */
const endHint = computed(() => {
    if (!plan.subscription_ends_at) return "No end date";
    const days = dayjs(plan.subscription_ends_at).startOf("day").diff(dayjs().startOf("day"), "day");
    if (days > 1) return `Ends in ${days} days`;
    if (days === 1) return "Ends tomorrow";
    if (days === 0) return "Ends today";
    return days === -1 ? "Ended yesterday" : `Ended ${-days} days ago`;
});
const endHintClass = computed(() => {
    if (!plan.subscription_ends_at) return "text-gray-500";
    const days = dayjs(plan.subscription_ends_at).startOf("day").diff(dayjs().startOf("day"), "day");
    return days < 0 ? "text-red-600" : days <= 7 ? "text-amber-600" : "text-gray-500";
});
const endBeforeStart = (current) =>
    !!plan.subscription_started_at && current && current.isBefore(dayjs(plan.subscription_started_at), "day");

/*** === LOCK (an organization that hasn't paid: its users only see the "on hold" page) === ***/
const locking = reactive({ saving: false });
const setLocked = (locked) => {
    locking.saving = true;
    router.patch(
        route("domains.lock.update", { domain: domain.value.name_slug }),
        { locked },
        {
            preserveScroll: true,
            onSuccess: () => notification.success({ message: locked ? "Organization locked" : "Organization unlocked" }),
            onError: () => notification.error({ message: "Couldn't change the lock" }),
            onFinish: () => (locking.saving = false),
        },
    );
};
const lockedSince = computed(() => (props.billing?.locked_at ? dayjs(props.billing.locked_at).format("MMM D, YYYY") : null));

const planOptions = computed(() => [
    { value: null, label: `Free starter · ${props.billing?.free_credits ?? 0} AI credits` },
    ...(props.billing?.tiers ?? []).map((tier) => ({
        value: tier.id,
        label: `${tier.name} · ₱${Number(tier.amount).toLocaleString("en-PH", { minimumFractionDigits: 2 })} · ${
            tier.ai_monthly_credits == null ? "unlimited" : tier.ai_monthly_credits.toLocaleString()
        } AI credits`,
    })),
]);

/** What the chosen plan includes, shown as the override's placeholder. */
const planCreditsPlaceholder = computed(() => {
    if (plan.service_tier_id === null) return `Plan default: ${props.billing?.free_credits ?? 0}`;
    const tier = (props.billing?.tiers ?? []).find((t) => t.id === plan.service_tier_id);
    return tier?.ai_monthly_credits == null ? "Plan default: unlimited" : `Plan default: ${tier.ai_monthly_credits.toLocaleString()}`;
});

const aiCost = computed(() => `$${Number(props.billing?.ai_cost_this_month_usd ?? 0).toFixed(2)}`);

const savePlan = () => {
    plan.saving = true;
    router.patch(
        route("domains.subscription.update", { domain: domain.value.name_slug }),
        {
            service_tier_id: plan.service_tier_id,
            ai_monthly_credits_override: plan.ai_monthly_credits_override,
            subscription_started_at: plan.subscription_started_at,
            subscription_ends_at: plan.subscription_ends_at,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                Object.assign(plan, savedPlan());
                notification.success({ message: "Plan updated" });
            },
            onError: (errors) => notification.error({ message: errors.subscription_ends_at ?? "Couldn't save the plan" }),
            onFinish: () => (plan.saving = false),
        },
    );
};

// Methods
const handleEdit = () => {
    router.visit(route("domains.edit", { domain: domain.value.name_slug }));
};

const handleBack = () => {
    router.visit(route("domains.index"));
};
</script>

<template>
    <Head :title="`Organization: ${domain.name}`" />

    <AuthenticatedLayout>
        <div class="w-full min-w-0">
            <ContentHeader class="mb-6" :title="domain.name">
                <template #actions>
                    <a-button @click="handleBack">
                        <template #icon><IconArrowLeft :size="16" class="mr-1 inline" /></template>
                        Back
                    </a-button>
                    <a-button type="primary" @click="handleEdit">
                        <template #icon><IconEdit :size="16" class="mr-1 inline" /></template>
                        Edit
                    </a-button>
                </template>
            </ContentHeader>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main column -->
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <!-- Basic information -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 text-lg font-semibold text-gray-900">Basic information</h2>
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm text-gray-500">Name</dt>
                                <dd class="font-medium text-gray-900">{{ domain.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Slug</dt>
                                <dd class="font-mono text-gray-900">{{ domain.name_slug }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Status</dt>
                                <dd class="flex">
                                    <a-tag :color="domain.is_active ? 'green' : 'red'">
                                        {{ domain.is_active ? "Active" : "Inactive" }}
                                    </a-tag>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Created</dt>
                                <dd class="text-gray-900">{{ formatDate(domain.created_at) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Regional settings -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
                            <IconMapPin :size="20" /> Regional settings
                        </h2>
                        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <div v-for="item in regional" :key="item.label">
                                <dt class="text-sm text-gray-500">{{ item.label }}</dt>
                                <dd class="text-gray-900">{{ item.value || "Not set" }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Users -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
                            <IconUsers :size="20" /> Users ({{ domain.users?.length || 0 }})
                        </h2>
                        <div v-if="domain.users?.length" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div
                                v-for="user in domain.users"
                                :key="user.id"
                                class="flex items-center gap-3 rounded-lg bg-gray-50 p-3"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100">
                                    <span class="text-sm font-semibold text-blue-600">
                                        {{ (user.name || "?").charAt(0).toUpperCase() }}
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-gray-900">{{ user.name }}</p>
                                    <p class="truncate text-sm text-gray-500">{{ user.email }}</p>
                                </div>
                                <a-tag :color="user.is_super_user ? 'purple' : 'green'">
                                    {{ user.is_super_user ? "Super user" : "User" }}
                                </a-tag>
                            </div>
                        </div>
                        <div v-else class="py-8 text-center text-gray-500">
                            <IconUsers class="mx-auto mb-2 h-12 w-12 text-gray-300" />
                            <p>No users in this organization yet</p>
                        </div>
                    </section>
                </div>

                <!-- Side column -->
                <div class="min-w-0 space-y-6">
                    <!-- Features (paid add-ons; off until a super admin turns them on) -->
                    <section
                        v-if="isSuperUser"
                        class="rounded-lg bg-white p-6"
                        data-testid="domain-features"
                    >
                        <h2 class="mb-1 text-lg font-semibold text-gray-900">Features</h2>
                        <p class="mb-4 text-sm text-gray-500">
                            Turned-off features are hidden from this organization and closed to it.
                        </p>

                        <div class="space-y-4">
                            <a-checkbox
                                v-model:checked="features.finance"
                                class="!ml-0 flex items-start"
                                data-testid="feature-finance"
                            >
                                <span class="flex items-center gap-1 font-medium">
                                    <IconReportAnalytics :size="16" /> Finance
                                </span>
                                <span class="block text-xs text-gray-500">
                                    Finance dashboard, statements, expenses, payables, VAT report
                                    and monthly reviews, with AI explanations ("Explain this",
                                    "Ask about your business").
                                </span>
                            </a-checkbox>

                            <a-checkbox
                                v-model:checked="features.ai"
                                class="!ml-0 flex items-start"
                                data-testid="feature-ai"
                            >
                                <span class="flex items-center gap-1 font-medium">
                                    <IconSparkles :size="16" /> AI assistant
                                </span>
                                <span class="block text-xs text-gray-500">
                                    Help assistant in the chat drawer: teaches staff step by step
                                    and can do tasks for them after they confirm.
                                </span>
                            </a-checkbox>
                        </div>

                        <a-button
                            type="primary"
                            class="mt-5 w-full"
                            :loading="features.saving"
                            :disabled="!featuresChanged"
                            data-testid="save-features"
                            @click="saveFeatures"
                        >
                            Save features
                        </a-button>
                    </section>

                    <!-- Plan & AI credits (super admins; the AI cost stays here, never shown to the organization) -->
                    <section
                        v-if="isSuperUser && billing"
                        class="rounded-lg bg-white p-6"
                        data-testid="domain-plan"
                    >
                        <h2 class="mb-1 text-lg font-semibold text-gray-900">Plan &amp; AI credits</h2>
                        <p class="mb-4 text-sm text-gray-500">
                            The plan sets the monthly AI credits. Custom credits replace the plan's for this organization.
                        </p>

                        <label class="mb-1 block text-sm font-medium text-gray-700">Plan</label>
                        <a-select
                            v-model:value="plan.service_tier_id"
                            :options="planOptions"
                            class="w-full"
                            data-testid="plan-select"
                        />

                        <!-- Subscription dates (paid plans; the end date only warns, it never locks by itself) -->
                        <div v-if="plan.service_tier_id !== null" class="mt-4 grid grid-cols-2 gap-3" data-testid="plan-dates">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Start</label>
                                <a-date-picker
                                    v-model:value="plan.subscription_started_at"
                                    value-format="YYYY-MM-DD"
                                    format="MMM D, YYYY"
                                    class="!w-full"
                                    placeholder="Today"
                                    data-testid="plan-start"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">End</label>
                                <a-date-picker
                                    v-model:value="plan.subscription_ends_at"
                                    value-format="YYYY-MM-DD"
                                    format="MMM D, YYYY"
                                    class="!w-full"
                                    placeholder="No end date"
                                    :disabled-date="endBeforeStart"
                                    data-testid="plan-end"
                                />
                            </div>
                            <p class="col-span-2 -mt-1 text-xs" :class="endHintClass" data-testid="plan-end-hint">
                                {{ endHint }}. Admins are reminded 7 days before; nothing locks by itself.
                            </p>
                        </div>

                        <label class="mb-1 mt-4 block text-sm font-medium text-gray-700">Custom monthly AI credits</label>
                        <a-input-number
                            v-model:value="plan.ai_monthly_credits_override"
                            :min="0"
                            :max="1000000"
                            :precision="0"
                            class="!w-full"
                            :placeholder="planCreditsPlaceholder"
                            data-testid="plan-credits-override"
                        />
                        <p class="mt-1 text-xs text-gray-500">Leave blank to follow the plan.</p>

                        <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm">
                            <p>
                                This month:
                                <strong class="tabular-nums">{{ billing.ai_credits.used.toLocaleString() }}</strong>
                                <template v-if="!billing.ai_credits.unlimited">
                                    / {{ billing.ai_credits.allowance.toLocaleString() }}
                                </template>
                                credits used
                                <a-tag v-if="billing.ai_credits.source === 'override'" color="blue" class="ml-1">Custom</a-tag>
                            </p>
                            <a-progress
                                v-if="!billing.ai_credits.unlimited"
                                :percent="billing.ai_credits.percent"
                                :status="billing.ai_credits.remaining === 0 ? 'exception' : 'normal'"
                                :show-info="false"
                                class="!mb-0"
                            />
                            <p class="mt-1 text-gray-600">
                                Estimated AI cost: <strong class="tabular-nums">{{ aiCost }}</strong>
                                <span class="text-xs text-gray-400">(only super admins see this)</span>
                            </p>
                            <a v-if="features.ai" :href="billing.ai_usage_url" class="mt-1 inline-block text-xs font-medium text-blue-600 hover:underline">
                                View AI usage →
                            </a>
                        </div>

                        <a-alert
                            v-if="!features.ai && !features.finance"
                            class="mt-3"
                            type="info"
                            show-icon
                            message="AI is off for this organization (neither Finance nor the AI assistant is on), so no credits will be used."
                        />

                        <a-button
                            type="primary"
                            class="mt-5 w-full"
                            :loading="plan.saving"
                            :disabled="!planChanged"
                            data-testid="save-plan"
                            @click="savePlan"
                        >
                            Save plan
                        </a-button>
                    </section>

                    <!-- Access: lock an organization that hasn't paid (super admins) -->
                    <section v-if="isSuperUser && billing" class="rounded-lg bg-white p-6" data-testid="domain-lock">
                        <h2 class="mb-1 text-lg font-semibold text-gray-900">Access</h2>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-gray-800">Lock: subscription not paid</p>
                                <p class="text-xs text-gray-500">Users can still log in but only see an "on hold" page.</p>
                            </div>
                            <a-popconfirm
                                :title="billing.locked_at ? `Unlock ${domain.name}? Its users get their pages back.` : `Lock ${domain.name}? Its users will only see the on-hold page.`"
                                :ok-text="billing.locked_at ? 'Unlock' : 'Lock'"
                                cancel-text="Cancel"
                                @confirm="setLocked(!billing.locked_at)"
                            >
                                <a-switch :checked="!!billing.locked_at" :loading="locking.saving" data-testid="lock-switch" />
                            </a-popconfirm>
                        </div>
                        <a-alert
                            v-if="billing.locked_at"
                            class="mt-3"
                            type="error"
                            show-icon
                            :message="`Locked since ${lockedSince}. Users only see the on-hold page.`"
                        />
                    </section>

                    <!-- Dates -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 text-lg font-semibold text-gray-900">Activity</h2>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Created</dt>
                                <dd class="text-right text-gray-900">{{ formatDate(domain.created_at) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Updated</dt>
                                <dd class="text-right text-gray-900">{{ formatDate(domain.updated_at) }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
