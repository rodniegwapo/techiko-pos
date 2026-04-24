<script setup>
import { Head, usePage } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    seo: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const appUrl = computed(() => page.props.appUrl);
const canonical = computed(() => appUrl.value + (props.seo.path || ""));

const brand = import.meta.env.VITE_APP_NAME || "Techiko POS";
const socialTitle = computed(
    () => `${brand} — ${props.seo.title}`,
);
</script>

<template>
    <Head :title="seo.title">
        <meta
            head-key="description"
            name="description"
            :content="seo.description"
        />
        <link rel="canonical" :href="canonical" />
        <meta
            property="og:title"
            :content="socialTitle"
            head-key="og:title"
        />
        <meta
            property="og:description"
            :content="seo.description"
            head-key="og:description"
        />
        <meta property="og:url" :content="canonical" head-key="og:url" />
        <meta
            property="og:type"
            content="website"
            head-key="og:type"
        />
        <meta
            property="og:image"
            :content="seo.ogImage"
            head-key="og:image"
        />
        <meta
            name="twitter:card"
            content="summary_large_image"
            head-key="twitter:card"
        />
        <meta
            name="twitter:title"
            :content="socialTitle"
            head-key="twitter:title"
        />
        <meta
            name="twitter:description"
            :content="seo.description"
            head-key="twitter:description"
        />
    </Head>
</template>
