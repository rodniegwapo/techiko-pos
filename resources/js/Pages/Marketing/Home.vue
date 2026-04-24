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
            class="relative overflow-hidden border-b border-blue-400/30 bg-gradient-to-br from-blue-600 via-blue-600 to-blue-500"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-white/10"
            />
            <div
                class="pointer-events-none absolute -right-20 -top-16 h-64 w-64 rounded-full bg-white/15 blur-3xl md:h-80 md:w-80"
            />
            <div
                class="relative mx-auto max-w-6xl px-4 py-12 md:py-20 md:px-4 lg:grid lg:grid-cols-2 lg:gap-12 lg:items-center lg:py-24"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-white drop-shadow-sm md:text-4xl lg:text-5xl"
                    >
                        Your store, your pace — one POS that keeps up
                    </h1>
                    <p
                        class="mt-4 max-w-xl text-base text-blue-100 leading-relaxed md:mt-5 md:text-lg"
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
                            class="inline-flex items-center justify-center rounded-xl bg-white px-5 py-3 text-center text-base font-semibold text-blue-700 shadow-lg transition hover:bg-blue-50"
                        >
                            Sign in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="inline-flex items-center justify-center rounded-xl border-2 border-white/50 bg-white/10 px-5 py-3 text-center text-base font-semibold text-white backdrop-blur-sm transition hover:border-white hover:bg-white/20"
                        >
                            Create account
                        </Link>
                        <Link
                            :href="route('marketing.services')"
                            class="inline-flex items-center justify-center py-1 text-sm font-semibold text-white/95 underline decoration-blue-200/90 decoration-2 underline-offset-4 transition hover:text-white sm:pl-1"
                        >
                            See all features
                        </Link>
                    </div>
                </div>
                <div
                    class="mt-10 rounded-2xl border border-white/30 bg-white/10 p-6 shadow-2xl shadow-blue-900/10 backdrop-blur-md md:p-7 lg:mt-0"
                >
                    <p
                        class="text-sm font-semibold uppercase tracking-wide text-blue-100"
                    >
                        At a glance
                    </p>
                    <ul
                        class="mt-4 space-y-3 text-sm text-white md:text-base"
                    >
                        <li class="flex items-start gap-3">
                            <span
                                class="mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full border-2 border-white/80 bg-white"
                            />
                            Fast checkout with flexible discounts
                        </li>
                        <li class="flex items-start gap-3">
                            <span
                                class="mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full border-2 border-white/80 bg-white"
                            />
                            Multi-location inventory visibility
                        </li>
                        <li class="flex items-start gap-3">
                            <span
                                class="mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full border-2 border-white/80 bg-white"
                            />
                            Customer loyalty and reporting in one place
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <div
            class="border-b border-gray-200 bg-blue-50 py-4 text-center md:py-5"
        >
            <p class="text-sm font-medium text-gray-800 md:text-base">
                Built for retail and hospitality
            </p>
            <p
                class="mx-auto mt-2 max-w-2xl px-4 text-xs text-gray-600 leading-relaxed md:text-sm"
            >
                Data handled with industry-standard security practices. See
                <Link
                    :href="route('marketing.services')"
                    class="font-medium text-blue-600 transition hover:text-blue-700"
                    >Services</Link
                >
                or
                <Link
                    :href="route('marketing.contact')"
                    class="font-medium text-blue-600 transition hover:text-blue-700"
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
