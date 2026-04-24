<script setup>
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import { Link } from "@inertiajs/vue3";

const nav = [
    { name: "Home", href: "marketing.home" },
    { name: "Services", href: "marketing.services" },
    { name: "About", href: "marketing.about" },
    { name: "Pricing", href: "marketing.pricing" },
    { name: "Contact", href: "marketing.contact" },
];
</script>

<template>
    <div
        class="min-h-screen flex flex-col bg-gray-50 text-gray-800"
    >
        <header
            class="border-b border-gray-200 bg-white shadow-sm"
        >
            <div
                class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center justify-between">
                    <Link
                        :href="route('marketing.home')"
                        class="flex items-center gap-2 font-semibold text-gray-800 transition hover:opacity-90"
                    >
                        <ApplicationLogo class="!w-[88px]" />
                    </Link>
                </div>
                <nav
                    class="flex flex-wrap items-center justify-center gap-1 sm:gap-2"
                    aria-label="Primary"
                >
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="route(item.href)"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-blue-50 hover:text-teal-700"
                    >
                        {{ item.name }}
                    </Link>
                    <Link
                        :href="route('login')"
                        class="ml-0 rounded-lg bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:from-blue-700 hover:to-teal-600 sm:ml-2"
                    >
                        Log in
                    </Link>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="mt-auto border-t border-gray-200 bg-gray-100">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-gray-600">
                    &copy; {{ new Date().getFullYear() }} Techiko POS. All
                    rights reserved.
                </p>
                <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                    <Link
                        :href="route('marketing.services')"
                        class="transition hover:text-blue-600"
                    >
                        Services
                    </Link>
                    <Link
                        :href="route('marketing.contact')"
                        class="transition hover:text-blue-600"
                    >
                        Contact
                    </Link>
                    <Link
                        :href="route('login')"
                        class="transition hover:text-blue-600"
                    >
                        Log in
                    </Link>
                </div>
            </div>
        </footer>
    </div>
</template>
