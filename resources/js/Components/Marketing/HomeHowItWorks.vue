<script setup>
import { Link } from "@inertiajs/vue3";
import { IconUserPlus, IconMapPin, IconCash } from "@tabler/icons-vue";
</script>

<template>
    <section
        class="border-b border-gray-200 bg-gray-100 py-14 md:py-20"
        aria-labelledby="how-heading"
    >
        <div class="mx-auto max-w-6xl px-4">
            <h2
                id="how-heading"
                class="text-2xl font-bold tracking-tight text-gray-800 md:text-3xl"
            >
                How it works
            </h2>
            <p class="mt-3 max-w-2xl text-gray-600 leading-relaxed md:text-lg">
                Get from zero to “open for business” in three straightforward
                steps.
            </p>
            <ol
                class="mt-10 grid gap-8 md:grid-cols-3"
            >
                <li
                    class="relative flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-md"
                >
                    <span
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-blue-500 text-sm font-bold text-white shadow"
                    >
                        1
                    </span>
                    <div
                        class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-blue-500"
                    >
                        <IconUserPlus
                            :size="24"
                            :strokeWidth="1.75"
                            color="white"
                            class="shrink-0"
                        />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800">
                        Create your account
                    </h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                        Register and invite the people who will run the
                        register and manage stock.
                    </p>
                    <Link
                        :href="route('register')"
                        class="mt-4 text-sm font-semibold text-blue-700 hover:text-blue-900"
                    >
                        Start registration →
                    </Link>
                </li>
                <li
                    class="relative flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-md"
                >
                    <span
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-blue-500 text-sm font-bold text-white shadow"
                    >
                        2
                    </span>
                    <div
                        class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-blue-500"
                    >
                        <IconMapPin
                            :size="24"
                            :strokeWidth="1.75"
                            color="white"
                            class="shrink-0"
                        />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800">
                        Set your location
                    </h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                        Configure your store or site so products and stock map
                        to the right place.
                    </p>
                </li>
                <li
                    class="relative flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-md"
                >
                    <span
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-blue-500 text-sm font-bold text-white shadow"
                    >
                        3
                    </span>
                    <div
                        class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-blue-500"
                    >
                        <IconCash
                            :size="24"
                            :strokeWidth="1.75"
                            color="white"
                            class="shrink-0"
                        />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800">
                        Sell and track
                    </h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                        Open a sale, take payment, and let inventory and
                        reports stay in step automatically.
                    </p>
                    <Link
                        :href="route('login')"
                        class="mt-4 text-sm font-semibold text-blue-700 hover:text-blue-900"
                    >
                        Sign in to the app →
                    </Link>
                </li>
            </ol>
        </div>
    </section>
</template>
