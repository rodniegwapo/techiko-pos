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
                Contact
            </h1>
            <p class="mt-4 text-lg text-slate-600 leading-relaxed">
                We read messages about product questions, feedback, and
                partnership interest. If you are already a customer, please use
                the same channel you use for support with your team when
                possible.
            </p>
            <div
                class="mt-10 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
            >
                <p class="text-slate-800 font-medium">Next step</p>
                <p class="mt-2 text-slate-600">
                    For account access, sign in and continue from your
                    dashboard.
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Link
                        :href="route('login')"
                        class="inline-flex rounded-lg bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-2.5 text-sm font-semibold text-white hover:from-blue-700 hover:to-teal-600"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('register')"
                        class="inline-flex rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                    >
                        Register
                    </Link>
                </div>
            </div>
        </div>
    </MarketingLayout>
</template>
