<script setup>
import { computed } from "vue";

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        required: true,
    },
    imageSrc: {
        type: String,
        default: "",
    },
    imageAlt: {
        type: String,
        default: "",
    },
    imageWidth: {
        type: Number,
        default: 801,
    },
    imageHeight: {
        type: Number,
        default: 636,
    },
});

const hasImage = computed(() => Boolean(props.imageSrc));
</script>

<template>
    <section
        class="relative overflow-hidden border-b border-teal-500/20 bg-gradient-to-br from-blue-600 to-teal-500"
        aria-labelledby="page-hero-heading"
    >
        <div
            class="pointer-events-none absolute inset-0 bg-white/10"
        />
        <div
            v-if="hasImage"
            class="pointer-events-none absolute -right-20 -top-16 hidden h-64 w-64 rounded-full bg-white/15 blur-3xl md:h-80 md:w-80 lg:block"
        />
        <div
            v-else
            class="pointer-events-none absolute -right-16 -top-12 hidden h-48 w-48 rounded-full bg-white/10 blur-3xl md:h-64 md:w-64 lg:block"
        />
        <div
            v-if="!hasImage"
            class="relative z-10 mx-auto max-w-6xl px-4 py-12 md:py-16 md:px-4"
        >
            <h1
                id="page-hero-heading"
                class="text-3xl font-bold tracking-tight text-white drop-shadow-sm md:text-4xl lg:text-5xl"
            >
                {{ title }}
            </h1>
            <p
                class="mt-3 max-w-2xl text-base text-blue-100 leading-relaxed drop-shadow-sm md:text-lg"
            >
                {{ subtitle }}
            </p>
        </div>
        <div
            v-else
            class="relative z-10 mx-auto max-w-6xl px-4 py-12 md:py-20 md:px-4 lg:grid lg:grid-cols-2 lg:gap-12 lg:items-center lg:py-24"
        >
            <div>
                <h1
                    id="page-hero-heading"
                    class="text-3xl font-bold tracking-tight text-white drop-shadow-sm md:text-4xl lg:text-5xl"
                >
                    {{ title }}
                </h1>
                <p
                    class="mt-3 max-w-2xl text-base text-blue-100 leading-relaxed drop-shadow-sm md:text-lg"
                >
                    {{ subtitle }}
                </p>
            </div>
            <div
                class="mt-10 hidden justify-center lg:mt-0 lg:flex lg:justify-end"
            >
                <img
                    :src="imageSrc"
                    :width="imageWidth"
                    :height="imageHeight"
                    class="h-auto w-full max-w-md object-contain drop-shadow-2xl lg:max-w-full"
                    :alt="imageAlt"
                    decoding="async"
                />
            </div>
        </div>
    </section>
</template>
