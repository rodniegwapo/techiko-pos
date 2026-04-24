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
    <div class="min-h-screen flex flex-col bg-slate-50 text-slate-900">
        <header
            class="border-b border-slate-200/80 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/80"
        >
            <div
                class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center justify-between">
                    <Link
                        :href="route('marketing.home')"
                        class="flex items-center gap-2 font-semibold text-slate-800 transition hover:opacity-90"
                    >
                        <ApplicationLogo class="!w-[100px]" />
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
                        class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
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

        <footer class="mt-auto border-t border-slate-200 bg-white">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-slate-500">
                    &copy; {{ new Date().getFullYear() }} Techiko POS. All
                    rights reserved.
                </p>
                <div class="flex flex-wrap gap-4 text-sm text-slate-600">
                    <Link
                        :href="route('marketing.services')"
                        class="hover:text-slate-900"
                        >Services</Link
                    >
                    <Link
                        :href="route('marketing.contact')"
                        class="hover:text-slate-900"
                        >Contact</Link
                    >
                    <Link :href="route('login')" class="hover:text-slate-900"
                        >Log in</Link
                    >
                </div>
            </div>
        </footer>
    </div>
</template>
