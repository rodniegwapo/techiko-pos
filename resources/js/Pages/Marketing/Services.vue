<script setup>
import MarketingPageHero from "@/Components/Marketing/MarketingPageHero.vue";
import MarketingSeoHead from "@/Components/MarketingSeoHead.vue";
import MarketingLayout from "@/Layouts/MarketingLayout.vue";
import { Link } from "@inertiajs/vue3";
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
        />

        <div class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-10 md:py-14">
                <p
                    class="max-w-3xl text-lg text-gray-800 leading-relaxed md:text-xl"
                >
                    Everything here is part of a single system — so you spend
                    less time connecting tools and more time running the floor.
                </p>

                <section
                    class="mt-12"
                    aria-labelledby="capabilities-heading"
                >
                    <h2
                        id="capabilities-heading"
                        class="text-xl font-bold tracking-tight text-gray-800 md:text-2xl"
                    >
                        What we offer
                    </h2>
                    <div
                        class="mt-8 grid gap-6 sm:grid-cols-2"
                    >
                        <article
                            v-for="(b, i) in blocks"
                            :key="b.title"
                            class="flex flex-col rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-sm transition hover:border-blue-200 hover:shadow-md"
                        >
                            <div
                                class="mb-4 flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-green-400 shadow-sm"
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
                            <h3 class="text-lg font-semibold text-gray-800">
                                {{ b.title }}
                            </h3>
                            <p class="mt-2 text-gray-600 leading-relaxed">
                                {{ b.text }}
                            </p>
                        </article>
                    </div>
                </section>

                <p class="mt-10 text-gray-600">
                    Have a specific stack or retail format?
                    <Link
                        :href="route('marketing.contact')"
                        class="font-semibold text-blue-600 transition hover:text-teal-600"
                    >
                        Get in touch
                    </Link>
                    — or explore
                    <Link
                        :href="route('marketing.pricing')"
                        class="font-semibold text-blue-600 transition hover:text-teal-600"
                    >
                        pricing &amp; plans
                    </Link>
                    to get started.
                </p>
            </div>
        </div>
    </MarketingLayout>
</template>
