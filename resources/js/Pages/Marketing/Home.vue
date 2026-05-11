<script setup>
import bannerSrc from "@assets/banner.svg";
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

const jsonLd = computed(() => {
    const url = appUrl.value;
    const logo = props.seo.ogImage || `${url}/images/og-default.png`;
    return JSON.stringify({
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "WebSite",
                "@id": `${url}/#website`,
                url,
                name: brand,
                description: props.seo.description,
                publisher: { "@id": `${url}/#organization` },
            },
            {
                "@type": "Organization",
                "@id": `${url}/#organization`,
                name: brand,
                url,
                logo,
            },
            {
                "@type": "SoftwareApplication",
                name: brand,
                applicationCategory: "BusinessApplication",
                applicationSubCategory: "Point of sale",
                operatingSystem: "Web",
                url,
                description: props.seo.description,
                provider: { "@id": `${url}/#organization` },
            },
        ],
    });
});
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
            class="relative overflow-hidden border-b border-teal-500/20 bg-gradient-to-br from-blue-600 to-teal-500"
        >
            <div class="pointer-events-none absolute inset-0 bg-white/10" />
            <div
                class="pointer-events-none absolute -right-20 -top-16 hidden h-64 w-64 rounded-full bg-white/15 blur-3xl md:h-80 md:w-80 lg:block"
            />
            <div
                class="relative mx-auto max-w-6xl px-4 py-12 md:py-20 md:px-4 lg:grid lg:grid-cols-2 lg:gap-12 lg:items-center lg:py-24"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-white drop-shadow-sm md:text-4xl lg:text-5xl"
                    >
                        Your store, your pace — one
                        <span class="text-green-400">POS</span>
                        that keeps up
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
                    class="mt-10 hidden justify-center lg:mt-0 lg:flex lg:justify-end"
                >
                    <img
                        :src="bannerSrc"
                        width="896"
                        height="748"
                        class="h-auto w-full max-w-md object-contain drop-shadow-2xl lg:max-w-full"
                        alt="Illustration of a retail checkout and point of sale experience"
                        decoding="async"
                    />
                </div>
            </div>
        </section>

        <div
            class="relative min-h-[5.5rem] overflow-hidden border-b border-gray-200 bg-white py-5 text-center md:min-h-[6rem] md:py-6"
        >
            <p
                class="relative z-10 text-sm font-medium text-gray-800 md:text-base"
            >
                Built for retail and hospitality
            </p>
            <p
                class="relative z-10 mx-auto mt-2 max-w-2xl px-4 text-xs text-gray-600 leading-relaxed md:text-sm"
            >
                Data handled with industry-standard security practices. See
                <Link
                    :href="route('marketing.services')"
                    class="font-medium text-blue-600 transition hover:text-blue-800"
                >
                    Services
                </Link>
                or
                <Link
                    :href="route('marketing.contact')"
                    class="font-medium text-blue-600 transition hover:text-teal-600"
                >
                    Contact
                </Link>
                for details.
            </p>
        </div>

        <HomeFeatureGrid />
        <HomeHowItWorks />
        <HomeCtaBand />
    </MarketingLayout>
</template>
