<script setup>
import { Link } from "@inertiajs/vue3";
import { IconUserPlus, IconMapPin, IconCash } from "@tabler/icons-vue";
</script>

<template>
    <section
        class="border-b border-slate-200 bg-slate-50/80 py-14 md:py-20"
        aria-labelledby="how-heading"
    >
        <div class="mx-auto max-w-6xl px-4">
            <h2
                id="how-heading"
                class="text-2xl font-bold tracking-tight text-slate-900 md:text-3xl"
            >
                How it works
            </h2>
            <p class="mt-3 max-w-2xl text-slate-600 leading-relaxed md:text-lg">
                Get from zero to “open for business” in three straightforward
                steps.
            </p>
            <ol
                class="mt-10 grid gap-8 md:grid-cols-3"
            >
                <li
                    class="relative flex flex-col rounded-2xl border border-slate-200/90 bg-white p-6 shadow-sm"
                >
                    <span
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white"
                    >
                        1
                    </span>
                    <div
                        class="mb-3 text-blue-600"
                    >
                        <IconUserPlus class="h-6 w-6" :stroke="1.75" />
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900">
                        Create your account
                    </h3>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        Register and invite the people who will run the
                        register and manage stock.
                    </p>
                    <Link
                        :href="route('register')"
                        class="mt-4 text-sm font-semibold text-blue-600 hover:text-blue-800"
                    >
                        Start registration →
                    </Link>
                </li>
                <li
                    class="relative flex flex-col rounded-2xl border border-slate-200/90 bg-white p-6 shadow-sm"
                >
                    <span
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white"
                    >
                        2
                    </span>
                    <div class="mb-3 text-teal-600">
                        <IconMapPin class="h-6 w-6" :stroke="1.75" />
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900">
                        Set your location
                    </h3>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        Configure your store or site so products and stock map
                        to the right place.
                    </p>
                </li>
                <li
                    class="relative flex flex-col rounded-2xl border border-slate-200/90 bg-white p-6 shadow-sm"
                >
                    <span
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white"
                    >
                        3
                    </span>
                    <div class="mb-3 text-blue-600">
                        <IconCash class="h-6 w-6" :stroke="1.75" />
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900">
                        Sell and track
                    </h3>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        Open a sale, take payment, and let inventory and
                        reports stay in step automatically.
                    </p>
                    <Link
                        :href="route('login')"
                        class="mt-4 text-sm font-semibold text-blue-600 hover:text-blue-800"
                    >
                        Sign in to the app →
                    </Link>
                </li>
            </ol>
        </div>
    </section>
</template>
