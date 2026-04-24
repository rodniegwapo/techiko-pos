<script setup>
import MarketingSeoHead from "@/Components/MarketingSeoHead.vue";
import MarketingLayout from "@/Layouts/MarketingLayout.vue";
import { Link } from "@inertiajs/vue3";

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
        <div class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
            <h1
                class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl"
            >
                Services & capabilities
            </h1>
            <p class="mt-4 max-w-2xl text-lg text-slate-600">
                Everything here is part of a single system — so you spend less
                time connecting tools and more time running the floor.
            </p>
            <div
                class="mt-12 grid gap-6 sm:grid-cols-2"
            >
                <article
                    v-for="b in blocks"
                    :key="b.title"
                    class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                >
                    <h2 class="text-lg font-semibold text-slate-900">
                        {{ b.title }}
                    </h2>
                    <p class="mt-2 text-slate-600 leading-relaxed">
                        {{ b.text }}
                    </p>
                </article>
            </div>
            <p class="mt-10 text-slate-600">
                Have a specific stack or retail format?
                <Link
                    :href="route('marketing.contact')"
                    class="font-semibold text-blue-600 hover:text-blue-700"
                    >Get in touch</Link
                >
                and we can walk through what maps to your operations.
            </p>
        </div>
    </MarketingLayout>
</template>
