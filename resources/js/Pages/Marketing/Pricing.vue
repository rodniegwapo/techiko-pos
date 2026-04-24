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
        <div class="mx-auto max-w-4xl px-4 py-16 sm:py-20">
            <h1
                class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl"
            >
                Pricing
            </h1>
            <p class="mt-4 max-w-2xl text-lg text-slate-600">
                We keep licensing straightforward: start with the core POS and
                grow into advanced inventory, loyalty, and multi-location
                features as you need them.
            </p>
            <div
                class="mt-12 grid gap-6 lg:grid-cols-2"
            >
                <div
                    class="rounded-2xl border-2 border-blue-200/80 bg-gradient-to-b from-white to-blue-50/40 p-8 shadow-lg shadow-blue-500/5"
                >
                    <h2 class="text-xl font-bold text-slate-900">Get started</h2>
                    <p class="mt-2 text-slate-600">
                        Create an account, configure your first location, and
                        invite your team. You can be live on a counter in a
                        single session.
                    </p>
                    <Link
                        :href="route('register')"
                        class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 py-3 text-center font-semibold text-white transition hover:from-blue-700 hover:to-teal-600"
                    >
                        Create account
                    </Link>
                </div>
                <div
                    class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
                >
                    <h2 class="text-xl font-bold text-slate-900">
                        Talk to us
                    </h2>
                    <p class="mt-2 text-slate-600">
                        If you are comparing POS options or have custom
                        workflows, tell us a bit on the contact page and we can
                        align the rollout with your process.
                    </p>
                    <Link
                        :href="route('marketing.contact')"
                        class="mt-6 inline-flex w-full items-center justify-center rounded-xl border border-slate-300 py-3 text-center font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50"
                    >
                        Contact
                    </Link>
                </div>
            </div>
        </div>
    </MarketingLayout>
</template>
