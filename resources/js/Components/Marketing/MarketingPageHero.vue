<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        required: true,
    },
});
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
            class="pointer-events-none absolute -right-16 -top-12 h-48 w-48 rounded-full bg-white/10 blur-3xl md:h-64 md:w-64"
        />
        <div class="relative mx-auto max-w-6xl px-4 py-12 md:py-16 md:px-4">
            <h1
                id="page-hero-heading"
                class="text-3xl font-bold tracking-tight text-white drop-shadow-sm md:text-4xl"
            >
                {{ title }}
            </h1>
            <p
                class="mt-3 max-w-2xl text-base text-blue-100 leading-relaxed md:text-lg"
            >
                {{ subtitle }}
            </p>
        </div>
    </section>
</template>
