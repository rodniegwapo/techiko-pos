<script setup>
import HomeCtaBand from "@/Components/Marketing/HomeCtaBand.vue";
import HomeFeatureGrid from "@/Components/Marketing/HomeFeatureGrid.vue";
import HomeHowItWorks from "@/Components/Marketing/HomeHowItWorks.vue";
import MarketingSeoHead from "@/Components/MarketingSeoHead.vue";
import MarketingLayout from "@/Layouts/MarketingLayout.vue";
import { Head, usePage, Link } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    seo: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const appUrl = computed(() => page.props.appUrl);
const brand = import.meta.env.VITE_APP_NAME || "Techiko POS";

const jsonLd = computed(() =>
    JSON.stringify({
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        name: brand,
        applicationCategory: "BusinessApplication",
        applicationSubCategory: "Point of sale",
        operatingSystem: "Web",
        url: appUrl.value,
        description: props.seo.description,
    }),
);
</script>

<template>
    <MarketingSeoHead :seo="seo" />
    <Head>
        <component
            :is="'script'"
            type="application/ld+json"
            head-key="ld-json-home"
            v-text="jsonLd"
        />
    </Head>
    <MarketingLayout>
        <section
            class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-br from-slate-50 via-white to-blue-50/50"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-[radial-gradient(#e2e8f0_1.2px,transparent_1.2px)] opacity-[0.5] [background-size:20px_20px] md:[background-size:24px_24px]"
            />
            <div
                class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-gradient-to-br from-blue-400/20 to-teal-400/20 blur-3xl md:h-96 md:w-96"
            />
            <div
                class="relative mx-auto max-w-6xl px-4 py-12 md:py-20 md:px-4 lg:grid lg:grid-cols-2 lg:gap-12 lg:items-center lg:py-24"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-slate-900 md:text-4xl lg:text-5xl"
                    >
                        Your store, your pace — one POS that keeps up
                    </h1>
                    <p
                        class="mt-4 max-w-xl text-base text-slate-600 leading-relaxed md:mt-5 md:text-lg"
                    >
                        One place for sales, stock, and loyalty. Built for
                        retail and hospitality teams that need a fast line and
                        clear numbers in the back office.
                    </p>
                    <div
                        class="mt-6 flex flex-col gap-3 sm:mt-8 sm:flex-row sm:flex-wrap sm:items-center"
                    >
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-teal-500 px-5 py-3 text-center text-base font-semibold text-white shadow-lg shadow-blue-500/20 transition hover:from-blue-700 hover:to-teal-600"
                        >
                            Sign in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-center text-base font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50"
                        >
                            Create account
                        </Link>
                        <Link
                            :href="route('marketing.services')"
                            class="inline-flex items-center justify-center py-1 text-sm font-semibold text-blue-600 transition hover:text-blue-800 sm:pl-1"
                        >
                            See all features
                        </Link>
                    </div>
                </div>
                <div
                    class="mt-10 rounded-2xl border border-slate-200/80 bg-white/90 p-6 shadow-xl shadow-slate-200/50 backdrop-blur md:p-7 lg:mt-0"
                >
                    <p
                        class="text-sm font-semibold uppercase tracking-wide text-teal-600"
                    >
                        At a glance
                    </p>
                    <ul
                        class="mt-4 space-y-3 text-slate-700 text-sm md:text-base"
                    >
                        <li class="flex items-start gap-2">
                            <span
                                class="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500"
                            />
                            Fast checkout with flexible discounts
                        </li>
                        <li class="flex items-start gap-2">
                            <span
                                class="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500"
                            />
                            Multi-location inventory visibility
                        </li>
                        <li class="flex items-start gap-2">
                            <span
                                class="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500"
                            />
                            Customer loyalty and reporting in one place
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <div
            class="border-b border-slate-200 bg-white py-4 text-center md:py-5"
        >
            <p class="text-sm font-medium text-slate-600 md:text-base">
                Built for retail and hospitality
            </p>
            <p
                class="mx-auto mt-2 max-w-2xl px-4 text-xs text-slate-500 leading-relaxed md:text-sm"
            >
                Data handled with industry-standard security practices. See
                <Link
                    :href="route('marketing.services')"
                    class="font-medium text-blue-600 hover:text-blue-800"
                    >Services</Link
                >
                or
                <Link
                    :href="route('marketing.contact')"
                    class="font-medium text-blue-600 hover:text-blue-800"
                    >Contact</Link
                >
                for details.
            </p>
        </div>

        <HomeFeatureGrid />
        <HomeHowItWorks />
        <HomeCtaBand />
    </MarketingLayout>
</template>
