<script setup>
import servicesSrc from "@assets/services.svg";
import MarketingHeroOrbit from "@/Components/Marketing/MarketingHeroOrbit.vue";
import MarketingPageHero from "@/Components/Marketing/MarketingPageHero.vue";
import MarketingSeoHead from "@/Components/MarketingSeoHead.vue";
import MarketingLayout from "@/Layouts/MarketingLayout.vue";
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import {
    IconChartBar,
    IconHeartHandshake,
    IconPackage,
    IconShoppingCart,
} from "@tabler/icons-vue";

defineProps({
    seo: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const showMarketingPricing = computed(
    () => !!page.props.features?.marketing_pricing_visible,
);

const blocks = [
    {
        title: "Point of sale",
        text: "Ring up items, apply discounts, and take payments in a flow designed to reduce training time and line wait.",
    },
    {
        title: "Inventory & locations",
        text: "See stock by location, receive and transfer, and keep adjustments auditable for your team.",
    },
    {
        title: "Customers & loyalty",
        text: "Track relationships and run loyalty so repeat visits are visible where your staff actually works.",
    },
    {
        title: "Analytics & control",
        text: "Supervision, roles, and reporting help you see performance without exporting ten spreadsheets first.",
    },
];
</script>

<template>
    <MarketingSeoHead :seo="seo" />
    <MarketingLayout>
        <MarketingPageHero
            title="Services & capabilities"
            subtitle="One connected platform for the counter, stockroom, and back office—built for real retail and hospitality teams."
            image-alt="Person reviewing analytics and services on a dashboard"
            :image-width="801"
            :image-height="636"
        />

        <div class="relative overflow-hidden border-b border-gray-200 bg-white">
            <MarketingHeroOrbit surface="light" layout="band" />
            <div class="relative z-10 mx-auto max-w-6xl px-4 py-10 md:py-14">
                <p
                    class="max-w-3xl text-base text-gray-800 leading-relaxed sm:text-lg md:text-xl"
                >
                    Everything here is part of a single system — so you spend
                    less time connecting tools and more time running the floor.
                </p>

                <section
                    class="mt-8 sm:mt-12"
                    aria-labelledby="capabilities-heading"
                >
                    <h2
                        id="capabilities-heading"
                        class="text-lg font-bold tracking-tight text-gray-800 sm:text-xl md:text-2xl"
                    >
                        What we offer
                    </h2>
                    <div
                        class="mt-6 grid grid-cols-1 gap-4 md:mt-8 md:grid-cols-2 md:gap-6"
                    >
                        <article
                            v-for="(b, i) in blocks"
                            :key="b.title"
                            class="flex min-h-0 flex-col rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm transition hover:border-blue-200 hover:shadow-md sm:p-6"
                        >
                            <div
                                class="flex items-start gap-3 sm:flex-col sm:gap-0"
                            >
                                <div
                                    class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-green-400 shadow-sm sm:mb-4"
                                >
                                    <IconShoppingCart
                                        v-if="i === 0"
                                        :size="22"
                                        :strokeWidth="1.75"
                                        color="white"
                                        class="shrink-0"
                                        aria-hidden="true"
                                    />
                                    <IconPackage
                                        v-else-if="i === 1"
                                        :size="22"
                                        :strokeWidth="1.75"
                                        color="white"
                                        class="shrink-0"
                                        aria-hidden="true"
                                    />
                                    <IconHeartHandshake
                                        v-else-if="i === 2"
                                        :size="22"
                                        :strokeWidth="1.75"
                                        color="white"
                                        class="shrink-0"
                                        aria-hidden="true"
                                    />
                                    <IconChartBar
                                        v-else
                                        :size="22"
                                        :strokeWidth="1.75"
                                        color="white"
                                        class="shrink-0"
                                        aria-hidden="true"
                                    />
                                </div>
                                <h3
                                    class="min-w-0 flex-1 pt-0.5 text-lg font-semibold text-gray-800 sm:flex-none sm:pt-0"
                                >
                                    {{ b.title }}
                                </h3>
                            </div>
                            <p
                                class="mt-3 text-sm text-gray-600 leading-relaxed sm:mt-2 sm:text-base"
                            >
                                {{ b.text }}
                            </p>
                        </article>
                    </div>
                </section>

                <p
                    class="mt-8 max-w-3xl text-sm text-gray-600 leading-relaxed sm:mt-10 sm:text-base"
                >
                    Have a specific stack or retail format?
                    <br class="sm:hidden" aria-hidden="true" />
                    <Link
                        :href="route('marketing.contact')"
                        class="font-semibold text-blue-600 transition hover:text-teal-600"
                    >
                        Get in touch
                    </Link>
                    <template v-if="showMarketingPricing">
                        — or explore
                        <Link
                            :href="route('marketing.pricing')"
                            class="font-semibold text-blue-600 transition hover:text-teal-600"
                        >
                            pricing &amp; plans
                        </Link>
                        to get started.
                    </template>
                    <template v-else> to get started.</template>
                </p>
            </div>
        </div>
    </MarketingLayout>
</template>
