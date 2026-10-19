<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ManualGcashDesktopQrAside from "@/Pages/Billing/ManualGcashDesktopQrAside.vue";
import ManualGcashPayment from "@/Pages/Billing/ManualGcashPayment.vue";
import AiCreditsMeter from "@/Components/ai/AiCreditsMeter.vue";
import PayPlanModal from "@/Components/billing/PayPlanModal.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { openTeamChat } from "@/Composables/useTeamChat";
import { message } from "ant-design-vue";
import { IconCheck } from "@tabler/icons-vue";
import dayjs from "dayjs";

/**
 * Billing: the organization's current plan and what it uses, the plans with Upgrade buttons, and
 * what was paid. Upgrade and Renew pay with a PayMongo QR Ph code (PayPlanModal); a paid code
 * switches the plan right away.
 */
const props = defineProps({
    tiers: { type: Array, default: () => [] }, // each with relation: current | upgrade | downgrade
    gcashQrUrl: { type: String, default: "/images/gcash-qr.svg" },
    currencySymbol: { type: String, default: "₱" },
    currentDomain: { type: Object, default: () => ({}) },
    subscription: { type: Object, default: null },
    showManualGcash: { type: Boolean, default: false },
    freeTier: { type: Object, default: null },
    /** This month's AI credits (every plan has the AI assistant). */
    aiCredits: { type: Object, default: null },
    /** The AI usage page, when this user may open it. */
    aiUsageUrl: { type: String, default: null },
    /** When the next payment is due ({ due_on, amount, days_left }), or null without a due date. */
    nextPayment: { type: Object, default: null },
    /** Past payments, newest first (read-only). */
    payments: { type: Array, default: () => [] },
    /** This user may pay for the organization. */
    canPay: { type: Boolean, default: false },
    /** Online payment (PayMongo) is set up. */
    paymentsReady: { type: Boolean, default: false },
    /** { id, name, slug, amount }, or null on the Free plan. */
    currentTier: { type: Object, default: null },
    /** VIP (set by Techiko): every feature, no limits, nothing to pay. */
    isVip: { type: Boolean, default: false },
    vipSince: { type: String, default: null },
});

const longDate = (date) => (date ? dayjs(date).format("MMM D, YYYY") : "");
const money = (amount) => `${props.currencySymbol}${Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`;
const limitOf = (count, max) => (max == null ? `${count ?? 0}` : `${count ?? 0} / ${max}`);
const dueLabel = (days) => {
    if (days > 1) return `due in ${days} days`;
    if (days === 1) return "due tomorrow";
    if (days === 0) return "due today";
    return days === -1 ? "overdue by 1 day" : `overdue by ${-days} days`;
};
const nextPaymentClass = computed(() => {
    const days = props.nextPayment?.days_left;
    return days < 0 ? "text-red-700" : days <= 7 ? "text-amber-700" : "text-gray-600";
});
const paymentColumns = [
    { title: "Paid on", dataIndex: "paid_on", key: "paid_on", customRender: ({ text }) => longDate(text) },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right", customRender: ({ text }) => money(text) },
    {
        title: "Covers",
        key: "covers",
        customRender: ({ record }) => `${longDate(record.period_start)} → ${longDate(record.period_end)}`,
    },
    { title: "Method", dataIndex: "method", key: "method" },
    { title: "Reference", dataIndex: "reference_number", key: "reference_number", ellipsis: true },
];

function aiCreditsLabel(credits) {
    return credits == null ? "Unlimited AI credits" : `${Number(credits).toLocaleString()} AI credits / month`;
}

const page = usePage();
const { getRoute } = useDomainRoutes();

watch(
    () => page.props.flash?.success,
    (msg) => msg && message.success(msg),
    { immediate: true },
);

const hasTiers = computed(() => props.tiers.length > 0);
const isPaid = computed(() => !!props.subscription?.is_paid);
/** Online payment can be offered to this user. */
const canPayOnline = computed(() => props.canPay && props.paymentsReady);

/*** === CURRENT PLAN === ***/
const usage = computed(() => {
    const s = props.subscription ?? {};
    const rows = [
        { label: "Users", value: limitOf(s.user_count, s.max_users) },
        { label: "Locations", value: limitOf(s.location_count, s.max_locations) },
    ];
    if (!s.products_unlimited) {
        rows.push({ label: "Products", value: limitOf(s.product_count, s.max_products) });
    }
    rows.push({ label: "Sales history", value: s.history_days == null ? "Full" : `Last ${s.history_days} days` });
    return rows;
});

/*** === PAY (upgrade / renew) === ***/
const payTiers = computed(() => props.tiers.filter((t) => t.relation !== "downgrade").map((t) => ({ id: t.id, name: t.name, amount: Number(t.amount) })));
const pay = ref({ open: false, tierId: null, title: "", note: "" });

const openUpgrade = (tier) => {
    pay.value = {
        open: true,
        tierId: tier.id,
        title: `Upgrade to ${tier.name}`,
        note: isPaid.value
            ? "{plan} starts right away; your paid-through date moves forward {months}."
            : "{plan} starts right away and is paid through {months} from today.",
    };
};
const openRenew = () => {
    pay.value = {
        open: true,
        tierId: props.currentTier?.id ?? null,
        title: "Pay for your plan",
        note: "Your paid-through date moves forward {months}.",
    };
};
const onPaid = () => {
    message.success("Payment received. Your plan is updated.");
    setTimeout(() => {
        pay.value.open = false;
        router.reload({ preserveScroll: true });
    }, 2000);
};

/** "Contact us" opens the chat with the Techiko team, with a message ready to send. */
const contactTeam = (text) => openTeamChat(text);

/*** === PLANS === ***/
const popularTierId = computed(() => {
    if (!props.tiers.length) return null;
    return (
        [...props.tiers].sort((a, b) => {
            const ao = Number(a.sort_order ?? 0);
            const bo = Number(b.sort_order ?? 0);
            if (ao !== bo) return ao - bo;
            return Number(a.amount) - Number(b.amount);
        })[0]?.id ?? null
    );
});

const freeTierLimitsLabel = computed(() => {
    const free = props.freeTier ?? {};
    const parts = [];
    if (props.subscription?.products_unlimited || free.product_limit == null) {
        parts.push("Unlimited products");
    } else {
        parts.push(`${free.product_limit ?? props.subscription?.free_product_limit} products`);
    }
    if (free.user_limit != null) parts.push(`${free.user_limit} users`);
    if (free.location_limit != null) parts.push(`${free.location_limit} location`);
    if (free.history_days != null) parts.push(`Last ${free.history_days} days of sales history`);
    if (props.aiCredits) parts.push(aiCreditsLabel(props.aiCredits.free_allowance));
    return parts.join(" · ");
});

function tierLimitsLabel(tier) {
    const parts = [];
    if (!props.subscription?.products_unlimited) {
        parts.push(tier.max_products != null ? `Max ${tier.max_products} products` : "Unlimited products");
    }
    parts.push(tier.max_users != null ? `Up to ${tier.max_users} users` : "Unlimited users");
    if (props.aiCredits) parts.push(aiCreditsLabel(tier.ai_monthly_credits));
    return parts.join(" · ");
}

const plansGridClass = computed(() => {
    const n = props.tiers.length + (props.freeTier ? 1 : 0);
    const base = "grid gap-6 w-full max-md:mx-auto max-md:max-w-md md:max-w-none";
    if (n <= 1) return `${base} grid-cols-1`;
    if (n <= 3) return `${base} grid-cols-1 md:grid-cols-3`;
    return `${base} grid-cols-1 sm:grid-cols-2 xl:grid-cols-4`;
});

function tierCardClasses(tier) {
    const base = "relative flex h-full flex-col rounded-2xl border p-6 text-left transition-all";
    if (tier.relation === "current") {
        return `${base} border-emerald-300 border-t-4 border-t-emerald-500 bg-white shadow-md`;
    }
    if (tier.id === popularTierId.value && tier.relation === "upgrade") {
        return `${base} border-gray-200 border-t-4 border-t-teal-500 bg-white shadow-lg`;
    }
    return `${base} border-gray-200 bg-gray-50 shadow-sm`;
}

/*** === MANUAL GCASH (off unless config manual_billing.show_manual_gcash_section) === ***/
const form = useForm({ service_tier_id: null, gcash_reference: "" });
const selectedTier = computed(() => props.tiers.find((t) => t.id === form.service_tier_id));
const qrSrc = computed(() => {
    const p = props.gcashQrUrl || "";
    if (p.startsWith("http")) return p;
    return p.startsWith("/") ? p : `/${p}`;
});
const showPlaceholderQrNotice = computed(() => import.meta.env.DEV && String(props.gcashQrUrl || "").includes("gcash-qr.svg"));
const referenceHelp = computed(
    () => form.errors.gcash_reference || "Usually letters and digits—paste the value shown on your GCash receipt.",
);
function submit() {
    if (!hasTiers.value) return;
    form.post(getRoute("billing.servicing.manual_gcash"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset("gcash_reference");
            form.clearErrors();
        },
    });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Billing" />
        <ContentHeader class="mb-6" title="Billing">
            <template #meta>Your plan, what it includes, and your payments.</template>
        </ContentHeader>

        <div class="mx-auto max-w-6xl space-y-6 pb-10">
            <!-- VIP: everything included, nothing to pay -->
            <section v-if="isVip" class="rounded-lg border border-amber-200 bg-white p-6 shadow-sm" data-testid="vip-plan">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Current plan</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <h2 class="text-2xl font-bold text-gray-900">VIP account</h2>
                            <a-tag color="gold" class="!m-0">VIP</a-tag>
                        </div>
                        <p class="mt-1 text-sm text-gray-600">
                            Every feature is included, with no limits on users, products or stores, and unlimited AI
                            credits. There is nothing to pay or renew.
                        </p>
                        <p v-if="vipSince" class="mt-1 text-xs text-gray-500">VIP since {{ longDate(vipSince) }}</p>
                    </div>
                    <a-button data-testid="contact-team" @click="contactTeam('I have a question about our VIP account.')">
                        Contact us
                    </a-button>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-gray-100 pt-5 sm:grid-cols-4" data-testid="plan-usage">
                    <div v-for="row in usage" :key="row.label" class="rounded-md bg-gray-50 px-3 py-2">
                        <dt class="text-xs text-gray-500">{{ row.label }}</dt>
                        <dd class="text-base font-semibold tabular-nums text-gray-900">{{ row.value }}</dd>
                    </div>
                </dl>

                <div v-if="aiCredits" class="mt-5 border-t border-gray-100 pt-5 text-sm">
                    <AiCreditsMeter :credits="aiCredits">
                        <Link v-if="aiUsageUrl" :href="aiUsageUrl" class="mt-2 inline-block text-xs font-medium text-blue-600 hover:underline">
                            See AI usage details →
                        </Link>
                    </AiCreditsMeter>
                </div>
            </section>

            <!-- Current plan and what it uses -->
            <section v-else class="rounded-lg bg-white p-6 shadow-sm" data-testid="current-plan">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Current plan</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <h2 class="text-2xl font-bold text-gray-900" data-testid="current-plan-name">
                                {{ currentTier?.name ?? "Free" }}
                            </h2>
                            <a-tag color="green" class="!m-0">Current plan</a-tag>
                        </div>
                        <p class="mt-1 text-sm text-gray-600">
                            <template v-if="currentTier">{{ money(currentTier.amount) }} / month</template>
                            <template v-else>{{ currencySymbol }}0 · Free forever</template>
                        </p>
                        <p v-if="nextPayment" class="mt-1 text-sm" :class="nextPaymentClass">
                            Paid through <strong>{{ longDate(nextPayment.due_on) }}</strong>
                            ({{ dueLabel(nextPayment.days_left) }})
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a-button
                            v-if="isPaid && canPayOnline"
                            type="primary"
                            data-testid="renew-plan"
                            @click="openRenew"
                        >
                            Pay / renew
                        </a-button>
                        <a-button data-testid="contact-team" @click="contactTeam(`I have a question about my plan (${currentTier?.name ?? 'Free'}).`)">
                            Contact us
                        </a-button>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-gray-100 pt-5 sm:grid-cols-4" data-testid="plan-usage">
                    <div v-for="row in usage" :key="row.label" class="rounded-md bg-gray-50 px-3 py-2">
                        <dt class="text-xs text-gray-500">{{ row.label }}</dt>
                        <dd class="text-base font-semibold tabular-nums text-gray-900">{{ row.value }}</dd>
                    </div>
                </dl>

                <div v-if="aiCredits" class="mt-5 border-t border-gray-100 pt-5 text-sm">
                    <AiCreditsMeter :credits="aiCredits" upgrade-hint="Upgrade your plan below, or wait for the reset.">
                        <Link v-if="aiUsageUrl" :href="aiUsageUrl" class="mt-2 inline-block text-xs font-medium text-blue-600 hover:underline">
                            See AI usage details →
                        </Link>
                    </AiCreditsMeter>
                </div>
            </section>

            <!-- Plans -->
            <section v-if="!isVip" class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="plans-heading">
                <h2 id="plans-heading" class="text-xl font-bold tracking-tight text-gray-800">Plans & pricing</h2>
                <p class="mt-2 max-w-2xl text-sm text-gray-600">
                    Every organization starts free. Each paid plan includes everything in the plans before it.
                    Upgrading pays with a QR Ph code, and the new plan starts as soon as it's paid.
                </p>
                <a-alert
                    v-if="!hasTiers"
                    class="mt-4"
                    type="warning"
                    show-icon
                    message="No plans available"
                    description="Ask your administrator to set up the plans."
                />
                <a-alert
                    v-else-if="!paymentsReady"
                    class="mt-4"
                    type="info"
                    show-icon
                    message="Online payment isn't set up yet. Contact us to change your plan."
                    data-testid="payments-not-ready"
                />

                <div :class="['mt-6', plansGridClass]">
                    <!-- Free -->
                    <div
                        v-if="freeTier"
                        class="relative flex h-full flex-col rounded-2xl border p-6"
                        :class="isPaid ? 'border-gray-200 bg-gray-50' : 'border-emerald-300 border-t-4 border-t-emerald-500 bg-white shadow-md'"
                        data-testid="plan-card-free"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <h3 class="text-lg font-bold text-gray-800">Free</h3>
                            <span
                                class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
                                :class="isPaid ? 'bg-slate-200 text-slate-600' : 'bg-emerald-100 text-emerald-900'"
                            >
                                {{ isPaid ? "Included" : "Current plan" }}
                            </span>
                        </div>
                        <p class="mt-2 text-3xl font-bold tabular-nums text-gray-800">{{ currencySymbol }}0</p>
                        <p class="text-sm text-gray-500">Per month · PHP</p>
                        <p class="pt-1 text-xs text-gray-500">{{ freeTierLimitsLabel }}</p>
                        <ul
                            v-if="freeTier.marketing_features?.length"
                            class="m-0 mt-5 flex flex-1 list-none flex-col gap-3 border-t border-gray-200 pt-5 text-sm text-gray-600"
                        >
                            <li v-for="(line, fx) in freeTier.marketing_features" :key="fx" class="flex gap-2">
                                <IconCheck :size="20" :stroke-width="1.75" class="mt-0.5 shrink-0 text-teal-600" aria-hidden="true" />
                                <span>{{ line }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Paid plans -->
                    <div
                        v-for="tier in tiers"
                        :key="tier.id"
                        :class="[tierCardClasses(tier), { 'cursor-pointer': showManualGcash }]"
                        :data-testid="`plan-card-${tier.slug}`"
                        @click="showManualGcash && (form.service_tier_id = tier.id)"
                    >
                        <p
                            v-if="tier.id === popularTierId && tier.relation === 'upgrade'"
                            class="mb-2 self-start rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-3 py-1 text-xs font-semibold text-white"
                        >
                            Most popular
                        </p>
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-lg font-bold leading-snug text-gray-800">{{ tier.name }}</h3>
                            <span
                                v-if="tier.relation === 'current'"
                                class="shrink-0 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-900"
                            >
                                Current plan
                            </span>
                        </div>
                        <p class="mt-2 text-3xl font-bold tabular-nums text-gray-800">{{ money(tier.amount) }}</p>
                        <p class="text-sm text-gray-500">Per month · PHP</p>
                        <p class="text-sm leading-snug text-gray-600">{{ tierLimitsLabel(tier) }}</p>
                        <ul
                            v-if="(tier.marketing_features ?? []).length"
                            class="m-0 mt-5 flex flex-1 list-none flex-col gap-3 border-t border-gray-200 pt-5 text-sm text-gray-600"
                        >
                            <li v-for="(line, fIdx) in tier.marketing_features" :key="fIdx" class="flex gap-2">
                                <IconCheck :size="20" :stroke-width="1.75" class="mt-0.5 shrink-0 text-teal-600" aria-hidden="true" />
                                <span>{{ line }}</span>
                            </li>
                        </ul>

                        <div class="mt-6">
                            <template v-if="tier.relation === 'upgrade'">
                                <a-button
                                    v-if="canPayOnline"
                                    type="primary"
                                    block
                                    :data-testid="`upgrade-${tier.slug}`"
                                    @click.stop="openUpgrade(tier)"
                                >
                                    Upgrade to {{ tier.name }}
                                </a-button>
                                <a-button
                                    v-else-if="canPay"
                                    block
                                    :data-testid="`upgrade-${tier.slug}`"
                                    @click.stop="contactTeam(`I'd like to upgrade to ${tier.name}.`)"
                                >
                                    Contact us to upgrade
                                </a-button>
                                <p v-else class="text-center text-xs text-gray-500">Ask your admin to upgrade.</p>
                            </template>
                            <a-button
                                v-else-if="tier.relation === 'downgrade' && canPay"
                                block
                                type="text"
                                class="!text-gray-500"
                                :data-testid="`switch-${tier.slug}`"
                                @click.stop="contactTeam(`I'd like to switch to ${tier.name}.`)"
                            >
                                Contact us to switch
                            </a-button>
                        </div>
                    </div>
                </div>
                <p class="mt-6 text-center text-sm text-gray-500">Prices in Philippine Pesos (PHP).</p>
            </section>

            <!-- Manual GCash (offline option, off by default) -->
            <section v-if="showManualGcash && !isVip" class="rounded-lg bg-white p-6 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold text-gray-900">Pay with GCash</h2>
                <p class="mb-4 text-sm text-gray-600">
                    Choose a plan above, send the amount to our GCash QR, then submit the reference number.
                </p>
                <div class="grid grid-cols-1 items-start gap-10 lg:grid-cols-2">
                    <ManualGcashPayment
                        :form="form"
                        :currency-symbol="currencySymbol"
                        :qr-src="qrSrc"
                        :show-placeholder-qr-notice="showPlaceholderQrNotice"
                        :reference-help="referenceHelp"
                        :selected-tier="selectedTier"
                        :has-tiers="hasTiers"
                        :submit="submit"
                    />
                    <ManualGcashDesktopQrAside :qr-src="qrSrc" :show-placeholder-qr-notice="showPlaceholderQrNotice" />
                </div>
            </section>

            <!-- Payments (recorded by Techiko or paid online) -->
            <section
                v-if="payments.length"
                class="rounded-lg bg-white p-6 text-sm text-gray-800 shadow-sm"
                data-testid="your-subscription"
            >
                <h2 class="mb-3 text-lg font-semibold text-gray-900">Payments</h2>
                <a-table
                    size="small"
                    :columns="paymentColumns"
                    :data-source="payments"
                    :pagination="false"
                    row-key="id"
                    :scroll="{ x: 640 }"
                />
            </section>
        </div>

        <PayPlanModal
            v-model:open="pay.open"
            :tiers="payTiers"
            :initial-tier-id="pay.tierId"
            :title="pay.title"
            :note="pay.note"
            paid-message="Payment received. Your plan is updated."
            :currency-symbol="currencySymbol"
            @paid="onPaid"
        />
    </AuthenticatedLayout>
</template>
