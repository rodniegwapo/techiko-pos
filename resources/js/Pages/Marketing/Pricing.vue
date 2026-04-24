<script setup>
import MarketingHeroOrbit from "@/Components/Marketing/MarketingHeroOrbit.vue";
import MarketingPageHero from "@/Components/Marketing/MarketingPageHero.vue";
import MarketingSeoHead from "@/Components/MarketingSeoHead.vue";
import MarketingLayout from "@/Layouts/MarketingLayout.vue";
import { Link } from "@inertiajs/vue3";
import { IconCheck } from "@tabler/icons-vue";

defineProps({
    seo: {
        type: Object,
        required: true,
    },
});

const currency = {
    code: "PHP",
    symbol: "₱",
};

const planPrices = {
    professional: 499,
    business: 999,
};

const focusLink =
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2";

const plans = [
    {
        name: "Free",
        featured: false,
        isFree: true,
        priceLabel: "Free",
        subLine: "No card required",
        features: [
            "Up to 100 products",
            "Up to 100 SKUs",
            "Core POS and checkout",
            "Single location",
        ],
        ctaLabel: "Create account",
        ctaHref: () => route("register"),
        ctaClass: `inline-flex w-full items-center justify-center rounded-xl border border-gray-300 bg-white py-3 text-center text-sm font-semibold text-gray-800 transition hover:border-blue-200 hover:bg-blue-50 ${focusLink}`,
    },
    {
        name: "Professional",
        featured: true,
        isFree: false,
        priceLabel: `${currency.symbol}${planPrices.professional}`,
        subLine: `Per month · ${currency.code}`,
        priceSuffix: "/ month",
        features: [
            "Unlimited products & SKUs",
            "One store / one location",
            "Inventory & loyalty features",
            "Email support",
        ],
        ctaLabel: "Contact us",
        ctaHref: () => route("marketing.contact"),
        ctaClass: `inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 py-3 text-center text-sm font-semibold text-white transition hover:from-blue-700 hover:to-teal-600 ${focusLink}`,
        badge: "Most popular",
    },
    {
        name: "Business",
        featured: false,
        isFree: false,
        priceLabel: `${currency.symbol}${planPrices.business}`,
        subLine: `Per month · ${currency.code}`,
        priceSuffix: "/ month",
        features: [
            "Unlimited products & SKUs",
            "Unlimited stores & locations",
            "Priority support",
            "Built for multi-site teams",
        ],
        ctaLabel: "Contact us",
        ctaHref: () => route("marketing.contact"),
        ctaClass: `inline-flex w-full items-center justify-center rounded-xl border border-gray-300 bg-white py-3 text-center text-sm font-semibold text-gray-800 transition hover:border-blue-200 hover:bg-blue-50 ${focusLink}`,
    },
];
</script>

<template>
    <MarketingSeoHead :seo="seo" />
    <MarketingLayout>
        <MarketingPageHero
            title="Pricing"
            subtitle="Straightforward plans in Philippine Pesos. Start free, then scale when you are ready."
        />

        <div class="relative overflow-hidden border-b border-gray-200 bg-white">
            <MarketingHeroOrbit surface="light" layout="band" />
            <div class="relative z-10 mx-auto max-w-6xl px-4 py-10 md:py-14">
                <p
                    class="max-w-2xl text-lg text-gray-800 leading-relaxed md:text-xl"
                >
                    We keep licensing simple: use the free tier to learn the
                    system, or talk to us about a paid plan when you need higher
                    limits and multi-store operations.
                </p>

                <section class="mt-12" aria-labelledby="plans-heading">
                    <h2
                        id="plans-heading"
                        class="text-xl font-bold tracking-tight text-gray-800 md:text-2xl"
                    >
                        Plans
                    </h2>
                    <div
                        class="mt-8 grid max-w-md gap-8 md:max-w-none md:grid-cols-3"
                    >
                        <div
                            v-for="p in plans"
                            :key="p.name"
                            class="mx-auto w-full"
                        >
                            <div
                                class="relative flex h-full min-h-full flex-col rounded-2xl border p-6 transition md:p-8"
                                :class="
                                    p.featured
                                        ? 'border border-gray-200 border-t-4 border-t-teal-500 bg-white shadow-lg hover:shadow-xl'
                                        : 'border border-gray-200 bg-gray-50 shadow-sm hover:border-blue-200 hover:shadow-md'
                                "
                            >
                                <p
                                    v-if="p.badge"
                                    class="mb-2 self-start rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-3 py-1 text-xs font-semibold text-white"
                                >
                                    {{ p.badge }}
                                </p>
                                <h3 class="text-lg font-bold text-gray-800">
                                    {{ p.name }}
                                </h3>
                                <div class="mt-2 space-y-1">
                                    <p
                                        v-if="p.isFree"
                                        class="text-3xl font-bold tabular-nums tracking-tight text-gray-800"
                                    >
                                        {{ p.priceLabel }}
                                    </p>
                                    <p
                                        v-else
                                        class="flex flex-wrap items-baseline gap-x-1.5"
                                    >
                                        <span
                                            class="text-3xl font-bold tabular-nums tracking-tight text-gray-800"
                                        >
                                            {{ p.priceLabel }}
                                        </span>
                                        <span
                                            class="text-sm font-medium text-gray-500"
                                        >
                                            {{ p.priceSuffix }}
                                        </span>
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        {{ p.subLine }}
                                    </p>
                                </div>
                                <ul
                                    class="mt-6 flex flex-1 flex-col gap-3 border-t border-gray-200 pt-6 text-sm text-gray-600"
                                >
                                    <li
                                        v-for="(line, i) in p.features"
                                        :key="i"
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
                                <Link
                                    :href="p.ctaHref()"
                                    :class="[p.ctaClass, 'mt-auto ']"
                                >
                                    {{ p.ctaLabel }}
                                </Link>
                            </div>
                        </div>
                    </div>
                    <p class="mt-8 text-center text-sm text-gray-500">
                        Prices in Philippine Pesos ({{ currency.code }}).
                    </p>
                </section>
            </div>
        </div>
    </MarketingLayout>
</template>
