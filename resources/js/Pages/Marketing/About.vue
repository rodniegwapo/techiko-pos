<script setup>
import MarketingHeroOrbit from "@/Components/Marketing/MarketingHeroOrbit.vue";
import MarketingPageHero from "@/Components/Marketing/MarketingPageHero.vue";
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
        <MarketingPageHero
            title="About Techiko POS"
            subtitle="Software that keeps checkout fast, stock honest, and your team focused on people—not busywork."
        />

        <div
            class="relative overflow-hidden border-b border-gray-200 bg-gray-50"
        >
            <MarketingHeroOrbit
                surface="light"
                layout="band"
            />
            <div
                class="relative z-10 mx-auto max-w-3xl px-4 py-10 md:py-14"
            >
                <div
                    class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm md:p-8"
                >
                    <p
                        class="border-l-4 border-green-400 pl-4 text-lg text-gray-600 leading-relaxed"
                    >
                        We focus on the moment between a customer and a team
                        member: it has to be fast, calm, and accurate. Techiko
                        POS brings sales, stock, and loyalty together so you
                        are not retyping data or second-guessing what happened
                        on the last shift.
                    </p>
                    <p class="mt-6 text-gray-600 leading-relaxed">
                        Whether you run one store or are growing a small chain,
                        the goal is the same: software that stays out of the way
                        on busy days and gives you something useful to read on
                        quiet ones.
                    </p>
                </div>

                <div
                    class="mt-10 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                >
                    <Link
                        :href="route('marketing.pricing')"
                        class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-blue-700 hover:to-teal-600"
                    >
                        View pricing &amp; get started
                    </Link>
                    <Link
                        :href="route('marketing.contact')"
                        class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-800 transition hover:border-blue-200 hover:bg-blue-50"
                    >
                        Contact
                    </Link>
                    <Link
                        :href="route('home')"
                        class="text-sm font-semibold text-blue-600 transition hover:text-teal-600"
                    >
                        Back to home
                    </Link>
                </div>
            </div>
        </div>
    </MarketingLayout>
</template>
