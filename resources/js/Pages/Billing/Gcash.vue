<script setup>
import { computed, watch } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import ManualGcashDesktopQrAside from "@/Pages/Billing/ManualGcashDesktopQrAside.vue";
import ManualGcashPayment from "@/Pages/Billing/ManualGcashPayment.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { message } from "ant-design-vue";
import { IconCheck } from "@tabler/icons-vue";

const props = defineProps({
    tiers: { type: Array, default: () => [] },
    gcashQrUrl: { type: String, default: "/images/gcash-qr.svg" },
    currencySymbol: { type: String, default: "₱" },
    currentDomain: { type: Object, default: () => ({}) },
    subscription: {
        type: Object,
        default: null,
    },
    showManualGcash: {
        type: Boolean,
        default: false,
    },
    freeTier: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const { getRoute } = useDomainRoutes();

const form = useForm({
    service_tier_id: null,
    gcash_reference: "",
});

const hasTiers = computed(
    () => Array.isArray(props.tiers) && props.tiers.length > 0,
);

const selectedTier = computed(() =>
    props.tiers.find((t) => t.id === form.service_tier_id),
);

const qrSrc = computed(() => {
    if (p.startsWith("http")) {
        return p;
    }
    return p.startsWith("/") ? p : `/${p}`;
});

const showPlaceholderQrNotice = computed(
    () =>
        import.meta.env.DEV &&
        String(props.gcashQrUrl || "").includes("gcash-qr.svg"),
);

const hasBundledQrPhOffered = computed(() =>
    props.tiers.some((t) => t.uses_vite_bundle_qrph),
);

const bundledQrTier = computed(
    () => props.tiers.find((t) => t.uses_vite_bundle_qrph) ?? null,
);

/** One paid tier flagged like Pricing “Most popular” (bundled/basic first, else lowest sort_order then amount). */
const popularTierId = computed(() => {
    const bundled = props.tiers.find((t) => t.uses_vite_bundle_qrph);
    if (bundled) {
        return bundled.id;
    }
    if (!props.tiers.length) {
        return null;
    }
    return (
        [...props.tiers].sort((a, b) => {
            const ao = Number(a.sort_order ?? 0);
            const bo = Number(b.sort_order ?? 0);
            if (ao !== bo) return ao - bo;
            return Number(a.amount) - Number(b.amount);
        })[0]?.id ?? null
    );
});

function isPopularTier(tier) {
    return popularTierId.value != null && tier.id === popularTierId.value;
}

const servicingPriceFootnote = computed(() => "One-time servicing · PHP");

const planCardSlots = computed(() => {
    let n = props.tiers.length;
    if (props.freeTier) {
        n += 1;
    }
    return n;
});

/** Match Marketing/Pricing grid rhythm: narrow column on mobile, 3 cols when Total ≤ 3. */
const plansGridClass = computed(() => {
    const n = planCardSlots.value;
    const base =
        "grid gap-8 w-full max-md:mx-auto max-md:max-w-md md:max-w-none";
    if (n <= 1) {
        return `${base} grid-cols-1`;
    }
    if (n <= 3) {
        return `${base} grid-cols-1 md:grid-cols-3`;
    }
    return `${base} grid-cols-1 sm:grid-cols-2 xl:grid-cols-4`;
});

const selectedTierAmountFormatted = computed(() =>
    selectedTier.value != null
        ? Number(selectedTier.value.amount).toFixed(2)
        : "",
);

const stepCurrentGcash = computed(() => {
    if (!form.service_tier_id) {
        return 0;
    }
    if (!String(form.gcash_reference || "").trim()) {
        return 1;
    }
    return 2;
});

const stepCurrentQrPh = computed(() =>
    form.service_tier_id || bundledQrTier.value ? 1 : 0,
);

watch(
    () => page.props.flash?.success,
    (msg) => {
        if (msg) {
            message.success(msg);
        }
    },
    { immediate: true },
);

function selectTier(id) {
    form.service_tier_id = id;
}

const freeTierMarketing = computed(
    () => props.freeTier?.marketing_features ?? [],
);

const freeTierSubLabel = computed(() => {
    if (!props.subscription) {
        return "Included when you join an organization.";
    }
    if (props.subscription.is_paid) {
        return "Starter baseline—not selectable. Use paid tiers below for servicing checkout.";
    }
    return "Your current tier. Upgrade anytime using a paid plan.";
});

function tierCardClasses(tier) {
    const selected = form.service_tier_id === tier.id;
    const popular = isPopularTier(tier);
    const base = [
        "relative flex h-full min-h-full flex-col rounded-2xl border p-6 text-left outline-none transition-all md:p-8",
        "cursor-pointer focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2",
    ];

    if (selected) {
        return [
            ...base,
            "border border-gray-200 border-t-4 border-t-teal-500 bg-white shadow-lg ring-2 ring-blue-500/15",
        ];
    }
    if (popular) {
        return [
            ...base,
            "border border-gray-200 border-t-4 border-t-teal-500 bg-white shadow-lg hover:shadow-xl",
        ];
    }

    return [
        ...base,
        "border border-gray-200 bg-gray-50 shadow-sm hover:border-blue-200 hover:shadow-md",
    ];
}

const qrPhPanelClass =
    "rounded-2xl border border-gray-200 border-t-4 border-t-teal-500 bg-white/90 shadow-sm space-y-3 p-6";

function tierLimitsLabel(tier) {
    const parts = [];
    if (tier.max_products != null) {
        parts.push(`Max ${tier.max_products} products`);
    } else {
        parts.push("Unlimited products");
    }
    if (tier.max_users != null) {
        parts.push(`Max ${tier.max_users} domain users`);
    } else {
        parts.push("Unlimited users");
    }
    return parts.join(" · ");
}

function submit() {
    if (!hasTiers.value) {
        return;
    }
    form.post(getRoute("billing.servicing.manual_gcash"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset("gcash_reference");
            form.clearErrors();
        },
    });
}

const freeTierBadge = computed(() => {
    if (!props.subscription) {
        return {
            label: "Included with signup",
            pillClass: "bg-slate-200 text-slate-800",
        };
    }
    if (props.subscription.is_paid) {
        return {
            label: "Starter baseline",
            pillClass: "bg-slate-200 text-slate-600",
        };
    }
    return {
        label: "Current tier",
        pillClass: "bg-emerald-100 text-emerald-900",
    };
});

const referenceHelp = computed(() => {
    if (form.errors.gcash_reference) {
        return form.errors.gcash_reference;
    }
    return "Usually letters and digits—paste the value shown on your GCash receipt.";
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Servicing plans & payment" />
        <ContentHeader class="mb-2" title="Servicing plans & payment" />
        <p class="mb-6 text-sm text-gray-600 max-w-2xl -mt-2">
            Upgrade your servicing subscription for higher limits and features.
            Techiko POS includes basic inventory, utang (basic), and wallet,
            with a
            <strong>30-day full-feature trial</strong> for qualifying
            organizations.
        </p>
        <ContentLayout :title="currentDomain?.name || 'Organization'">
            <template #table>
                <div
                    class="max-w-6xl mx-auto px-2 sm:px-4 py-2 pb-10 space-y-8"
                >
                    <div
                        class="text-sm text-gray-600 leading-relaxed border-l-4 border-teal-600/80 pl-4 py-2 bg-teal-50/40 rounded-r-lg"
                    >
                        <template v-if="showManualGcash">
                            Scan the merchant QR below for Basic, or submit a
                            manual GCash receipt reference. Amounts must match
                            the plan you selected.
                        </template>
                        <template v-else>
                            Paid tiers unlock limits after verified payment.
                            Basic can use the static merchant QR shown on this
                            page—it does not expire.
                        </template>
                    </div>

                    <a-steps
                        v-if="showManualGcash"
                        size="small"
                        :current="stepCurrentGcash"
                        class="[&_.ant-steps-item-title]:text-sm"
                    >
                        <a-step title="Choose plan" />
                        <a-step title="Pay with GCash" />
                        <a-step title="Submit reference" />
                    </a-steps>
                    <a-steps
                        v-else-if="hasBundledQrPhOffered"
                        size="small"
                        :current="stepCurrentQrPh"
                        class="[&_.ant-steps-item-title]:text-sm"
                    >
                        <a-step title="Choose plan" />
                        <a-step title="Pay with QR Ph" />
                    </a-steps>

                    <a-alert
                        v-if="!hasTiers"
                        type="warning"
                        message="No servicing plans available"
                        description="Ask your administrator to configure service tiers."
                        show-icon
                        class="mb-2"
                    />

                    <div
                        v-if="subscription"
                        class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm text-sm text-gray-800"
                    >
                        <p class="font-semibold text-gray-900 mb-1">
                            Current usage
                        </p>
                        <p>
                            <span v-if="subscription.is_paid">
                                Active servicing plan:
                                <strong>{{ subscription.tier_name }}</strong
                                >.
                            </span>
                            <span v-else-if="subscription.products_unlimited">
                                Product catalog is currently unlimited for this
                                organization. You can still upgrade to a paid
                                servicing tier for other benefits below.
                            </span>
                            <span v-else>
                                Free tier: up to
                                <strong>{{
                                    subscription.free_product_limit
                                }}</strong>
                                products until you subscribe to a paid plan.
                            </span>
                        </p>
                        <p class="mt-2 text-xs text-gray-600">
                            Products: {{ subscription.product_count }}
                            <template v-if="subscription.max_products != null">
                                / {{ subscription.max_products }}
                            </template>
                            · Users:
                            {{ subscription.user_count }}
                            <template v-if="subscription.max_users != null">
                                / {{ subscription.max_users }}
                            </template>
                        </p>
                    </div>

                    <div
                        class="grid grid-cols-1 items-start gap-10"
                        :class="{ 'lg:grid-cols-2 lg:gap-10': showManualGcash }"
                    >
                        <div class="space-y-8 min-w-0">
                            <section
                                class="border border-gray-200 bg-white py-8 md:py-10 px-3 sm:px-6 rounded-xl shadow-sm"
                                aria-labelledby="servicing-plans-heading"
                            >
                                <h2
                                    id="servicing-plans-heading"
                                    class="text-xl font-bold tracking-tight text-gray-800 md:text-2xl"
                                >
                                    Plans & pricing
                                </h2>
                                <p
                                    class="mt-3 max-w-2xl text-sm text-gray-600 md:text-base"
                                >
                                    Free starter is included; paid servicing
                                    tiers unlock higher limits below. Select a
                                    tier to continue (merchant QR and/or manual
                                    reference, depending on setup).
                                </p>

                                <div :class="['mt-8 md:mt-10', plansGridClass]">
                                    <div
                                        v-if="freeTier"
                                        class="relative mx-auto w-full flex flex-col rounded-2xl border border-gray-200 bg-gray-50 p-6 text-left opacity-95 pointer-events-none select-none md:p-8"
                                        aria-disabled="true"
                                        role="presentation"
                                    >
                                        <div
                                            class="flex flex-wrap items-start justify-between gap-2"
                                        >
                                            <h3
                                                class="text-lg font-bold text-gray-800"
                                            >
                                                Free starter
                                            </h3>
                                            <span
                                                class="rounded-full px-3 py-1 text-xs font-semibold shrink-0"
                                                :class="freeTierBadge.pillClass"
                                            >
                                                {{ freeTierBadge.label }}
                                            </span>
                                        </div>
                                        <div class="mt-2 space-y-1">
                                            <p
                                                class="flex flex-wrap items-baseline gap-x-1.5"
                                            >
                                                <span
                                                    class="text-3xl font-bold tabular-nums tracking-tight text-gray-800"
                                                    >{{ currencySymbol }}0<span
                                                        class="text-base font-semibold text-gray-600"
                                                        >.00</span
                                                    ></span
                                                >
                                            </p>
                                            <p class="text-sm text-gray-500">
                                                {{ servicingPriceFootnote }}
                                            </p>
                                            <p
                                                class="text-sm text-gray-600 leading-snug"
                                            >
                                                {{ freeTierSubLabel }}
                                            </p>
                                            <p
                                                class="text-xs text-gray-500 pt-1"
                                            >
                                                Cap:
                                                <template
                                                    v-if="
                                                        subscription?.products_unlimited ||
                                                        freeTier?.product_limit ==
                                                            null
                                                    "
                                                >
                                                    Unlimited products
                                                </template>
                                                <template v-else>
                                                    {{
                                                        freeTier.product_limit ??
                                                        subscription?.free_product_limit
                                                    }}
                                                    products
                                                </template>
                                                · utang/wallet basics
                                            </p>
                                        </div>
                                        <ul
                                            v-if="freeTierMarketing.length"
                                            class="mt-6 flex flex-1 flex-col gap-3 border-t border-gray-200 pt-6 text-sm text-gray-600 list-none m-0"
                                        >
                                            <li
                                                v-for="(
                                                    line, fx
                                                ) in freeTierMarketing"
                                                :key="fx"
                                                class="flex gap-2"
                                            >
                                                <IconCheck
                                                    :size="20"
                                                    :strokeWidth="1.75"
                                                    class="mt-0.5 shrink-0 text-teal-600"
                                                    aria-hidden="true"
                                                />
                                                <span>{{ line }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                    <div
                                        v-for="tier in tiers"
                                        :key="tier.id"
                                        class="mx-auto w-full"
                                    >
                                        <div
                                            role="button"
                                            tabindex="0"
                                            :class="tierCardClasses(tier)"
                                            @click="selectTier(tier.id)"
                                            @keydown.enter.prevent="
                                                selectTier(tier.id)
                                            "
                                        >
                                            <p
                                                v-if="isPopularTier(tier)"
                                                class="mb-2 self-start rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-3 py-1 text-xs font-semibold text-white"
                                            >
                                                Most popular
                                            </p>
                                            <div
                                                class="flex items-start justify-between gap-2"
                                            >
                                                <h3
                                                    class="text-lg font-bold text-gray-800 leading-snug"
                                                >
                                                    {{ tier.name }}
                                                </h3>
                                                <IconCheck
                                                    v-if="
                                                        form.service_tier_id ===
                                                        tier.id
                                                    "
                                                    :size="22"
                                                    :strokeWidth="2"
                                                    class="mt-1 shrink-0 text-teal-600"
                                                    aria-hidden="false"
                                                    aria-label="Selected plan"
                                                />
                                            </div>
                                            <p
                                                v-if="
                                                    form.service_tier_id ===
                                                    tier.id
                                                "
                                                class="mt-1 text-xs font-semibold text-teal-700"
                                            >
                                                Selected plan
                                            </p>
                                            <div class="mt-2 space-y-1">
                                                <p
                                                    class="flex flex-wrap items-baseline gap-x-1.5"
                                                >
                                                    <span
                                                        class="text-3xl font-bold tabular-nums tracking-tight text-gray-800"
                                                        >{{ currencySymbol
                                                        }}{{
                                                            Number(
                                                                tier.amount,
                                                            ).toFixed(2)
                                                        }}</span
                                                    >
                                                </p>
                                                <p
                                                    class="text-sm text-gray-500"
                                                >
                                                    {{ servicingPriceFootnote }}
                                                </p>
                                                <p
                                                    class="text-sm text-gray-600 leading-snug"
                                                >
                                                    {{ tierLimitsLabel(tier) }}
                                                </p>
                                            </div>
                                            <ul
                                                v-if="
                                                    (
                                                        tier.marketing_features ??
                                                        []
                                                    ).length
                                                "
                                                class="mt-6 flex flex-1 flex-col gap-3 border-t border-gray-200 pt-6 text-sm text-gray-600 list-none m-0"
                                            >
                                                <li
                                                    v-for="(
                                                        line, fIdx
                                                    ) in tier.marketing_features"
                                                    :key="fIdx"
                                                    class="flex gap-2"
                                                >
                                                    <IconCheck
                                                        :size="20"
                                                        :strokeWidth="1.75"
                                                        class="mt-0.5 shrink-0 text-teal-600"
                                                        aria-hidden="true"
                                                    />
                                                    <span>{{ line }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <p
                                    class="mt-8 text-center text-sm text-gray-500"
                                >
                                    Servicing amounts in Philippine Pesos (PHP).
                                </p>
                                <p
                                    v-if="form.errors.service_tier_id"
                                    class="text-red-500 text-sm mt-4 text-center"
                                >
                                    {{ form.errors.service_tier_id }}
                                </p>
                            </section>

                            <div class="mt-10 space-y-8">
                                <div
                                    v-if="hasTiers && bundledQrTier"
                                    :class="qrPhPanelClass"
                                >
                                    <h2
                                        class="text-xl font-bold tracking-tight text-gray-800"
                                    >
                                        Pay with QR Ph
                                    </h2>
                                    <p
                                        v-if="form.service_tier_id"
                                        class="text-sm text-gray-600"
                                    >
                                        Scan the code below with your bank or
                                        e-wallet app. Send exactly
                                        {{ currencySymbol
                                        }}{{ selectedTierAmountFormatted }}
                                        for
                                        <strong>{{ selectedTier.name }}</strong
                                        >.
                                    </p>
                                    <p
                                        v-else
                                        class="text-sm text-gray-600"
                                    >
                                        Select a plan above, then send exactly
                                        that amount when you scan—amounts
                                        differ by tier.
                                    </p>
                                    <div
                                        class="rounded-xl border border-gray-100 bg-white p-5 flex flex-col items-center shadow-sm"
                                    >
                                        <img
                                            src="@assets/qrph/qrph_basic.jpg"
                                            alt="QR Ph merchant code"
                                            class="max-w-[280px] w-full h-auto rounded-md"
                                        />
                                    </div>
                                </div>
                                <a-alert
                                    v-if="hasTiers && !bundledQrTier"
                                    type="warning"
                                    show-icon
                                    class="text-sm"
                                    message="No Basic merchant QR available"
                                    :description="
                                        showManualGcash
                                            ? 'The Basic bundled QR slug is missing or misconfigured. Use manual GCash below or contact your administrator.'
                                            : 'Ask your administrator to configure the bundled Basic servicing tier so this page can display the merchant QR.'
                                    "
                                />
                            </div>

                            <ManualGcashPayment
                                v-if="showManualGcash"
                                :form="form"
                                :currency-symbol="currencySymbol"
                                :qr-src="qrSrc"
                                :show-placeholder-qr-notice="
                                    showPlaceholderQrNotice
                                "
                                :reference-help="referenceHelp"
                                :selected-tier="selectedTier"
                                :has-tiers="hasTiers"
                                :submit="submit"
                            />
                        </div>

                        <ManualGcashDesktopQrAside
                            v-if="showManualGcash"
                            :qr-src="qrSrc"
                            :show-placeholder-qr-notice="
                                showPlaceholderQrNotice
                            "
                        />
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
