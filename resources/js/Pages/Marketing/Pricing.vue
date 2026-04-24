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

const plans = [
    {
        name: "Free",
        priceLabel: "Free",
        subPrice: "No card required",
        features: [
            "Up to 100 products",
            "Up to 100 SKUs",
            "Core POS and checkout",
            "Single location",
        ],
        ctaLabel: "Create account",
        ctaHref: () => route("register"),
        ctaClass:
            "inline-flex w-full items-center justify-center rounded-xl border border-gray-300 bg-white py-3 text-center text-sm font-semibold text-gray-800 transition hover:border-blue-200 hover:bg-blue-50",
        cardClass: "border-gray-200",
    },
    {
        name: "Professional",
        priceLabel: `${currency.symbol}${planPrices.professional}`,
        subPrice: "per month",
        features: [
            "Unlimited products & SKUs",
            "One store / one location",
            "Inventory & loyalty features",
            "Email support",
        ],
        ctaLabel: "Contact us",
        ctaHref: () => route("marketing.contact"),
        ctaClass:
            "inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 py-3 text-center text-sm font-semibold text-white transition hover:from-blue-700 hover:to-teal-600",
        cardClass: "border-blue-200 ring-2 ring-blue-100",
        badge: "Most popular",
    },
    {
        name: "Business",
        priceLabel: `${currency.symbol}${planPrices.business}`,
        subPrice: "per month",
        features: [
            "Unlimited products & SKUs",
            "Unlimited stores & locations",
            "Priority support",
            "Built for multi-site teams",
        ],
        ctaLabel: "Contact us",
        ctaHref: () => route("marketing.contact"),
        ctaClass:
            "inline-flex w-full items-center justify-center rounded-xl border border-gray-300 bg-white py-3 text-center text-sm font-semibold text-gray-800 transition hover:border-blue-200 hover:bg-blue-50",
        cardClass: "border-gray-200",
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

        <div
            class="relative overflow-hidden border-b border-gray-200 bg-white"
        >
            <MarketingHeroOrbit
                surface="light"
                layout="band"
            />
            <div
                class="relative z-10 mx-auto max-w-6xl px-4 py-10 md:py-14"
            >
                <p
                    class="max-w-2xl text-lg text-gray-800 leading-relaxed md:text-xl"
                >
                    We keep licensing simple: use the free tier to learn the
                    system, or talk to us about a paid plan when you need
                    higher limits and multi-store operations.
                </p>

                <section
                    class="mt-12"
                    aria-labelledby="plans-heading"
                >
                    <h2
                        id="plans-heading"
                        class="text-xl font-bold tracking-tight text-gray-800 md:text-2xl"
                    >
                        Plans
                    </h2>
                    <div
                        class="mt-8 grid gap-6 md:grid-cols-3"
                    >
                        <div
                            v-for="p in plans"
                            :key="p.name"
                            class="relative flex flex-col rounded-2xl border bg-white p-6 shadow-sm transition hover:shadow-md md:p-8"
                            :class="p.cardClass"
                        >
                            <p
                                v-if="p.badge"
                                class="absolute -top-3 right-4 rounded-full bg-gradient-to-r from-blue-600 to-teal-500 px-3 py-0.5 text-xs font-semibold text-white"
                            >
                                {{ p.badge }}
                            </p>
                            <h3 class="text-lg font-bold text-gray-800">
                                {{ p.name }}
                            </h3>
                            <p class="mt-3 flex items-baseline gap-1 text-gray-800">
                                <span class="text-3xl font-bold tracking-tight">
                                    {{ p.priceLabel }}
                                </span>
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ p.subPrice }}
                            </p>
                            <ul
                                class="mt-6 flex flex-1 flex-col gap-3 text-sm text-gray-600"
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
                                class="mt-8"
                                :class="p.ctaClass"
                            >
                                {{ p.ctaLabel }}
                            </Link>
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
