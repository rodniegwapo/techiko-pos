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
</script>

<template>
    <MarketingSeoHead :seo="seo" />
    <MarketingLayout>
        <div class="mx-auto max-w-3xl px-4 py-16 sm:py-20">
            <h1
                class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl"
            >
                About Techiko POS
            </h1>
            <div class="mt-8 max-w-none">
                <p class="text-lg text-slate-600 leading-relaxed">
                    We focus on the moment between a customer and a team member:
                    it has to be fast, calm, and accurate. Techiko POS brings
                    sales, stock, and loyalty together so you are not retyping
                    data or second-guessing what happened on the last shift.
                </p>
                <p class="mt-6 text-slate-600 leading-relaxed">
                    Whether you run one store or are growing a small chain, the
                    goal is the same: software that stays out of the way on busy
                    days and gives you something useful to read on quiet ones.
                </p>
            </div>
            <p class="mt-10">
                <Link
                    :href="route('marketing.pricing')"
                    class="font-semibold text-blue-600 hover:text-blue-700"
                    >View pricing &amp; get started</Link
                >
            </p>
        </div>
    </MarketingLayout>
</template>
