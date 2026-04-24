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
        <section
            class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-br from-slate-50 via-white to-blue-50/50"
        >
            <div
                class="mx-auto max-w-6xl px-4 py-20 sm:py-24 lg:py-28 lg:grid lg:grid-cols-2 lg:gap-12 lg:items-center"
            >
                <div>
                    <h1
                        class="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl"
                    >
                        Your store, your pace — one POS that keeps up
                    </h1>
                    <p class="mt-5 max-w-xl text-lg text-slate-600 leading-relaxed">
                        Run sales, track inventory, and reward customers without
                        switching tools. Techiko POS is built for teams that need
                        speed at the counter and clarity in the back office.
                    </p>
                    <div
                        class="mt-8 flex flex-wrap items-center gap-3"
                    >
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-5 py-3 text-base font-semibold text-white shadow-lg shadow-blue-500/20 transition hover:from-blue-700 hover:to-teal-600"
                        >
                            Sign in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-base font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50"
                        >
                            Create account
                        </Link>
                    </div>
                </div>
                <div
                    class="mt-10 rounded-2xl border border-slate-200/80 bg-white/80 p-6 shadow-xl shadow-slate-200/50 backdrop-blur lg:mt-0"
                >
                    <p class="text-sm font-semibold uppercase tracking-wide text-teal-600">
                        At a glance
                    </p>
                    <ul class="mt-4 space-y-3 text-slate-700">
                        <li class="flex items-start gap-2">
                            <span class="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500" />
                            Fast checkout with flexible discounts
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500" />
                            Multi-location inventory visibility
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500" />
                            Customer loyalty and reporting in one place
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </MarketingLayout>
</template>
