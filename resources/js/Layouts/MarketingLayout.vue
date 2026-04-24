<script setup>
import { ref, watch } from "vue";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import { Link, usePage } from "@inertiajs/vue3";

const nav = [
    { name: "Home", href: "marketing.home" },
    { name: "Services", href: "marketing.services" },
    { name: "About", href: "marketing.about" },
    { name: "Pricing", href: "marketing.pricing" },
    { name: "Contact", href: "marketing.contact" },
];

const isNavActive = (name) => route().current(name);

const page = usePage();
const mobileNavOpen = ref(false);

watch(
    () => page.url,
    () => {
        mobileNavOpen.value = false;
    },
);
</script>

<template>
    <div
        class="min-h-screen flex flex-col bg-gray-50 text-gray-800"
    >
        <header
            class="border-b border-gray-200 bg-white shadow-sm"
        >
            <div
                class="mx-auto max-w-6xl px-4 py-4"
            >
                <div
                    class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                >
                    <div
                        class="flex w-full items-center justify-between md:w-auto"
                    >
                        <Link
                            :href="route('marketing.home')"
                            class="flex items-center gap-2 font-semibold text-gray-800 transition hover:opacity-90"
                        >
                            <ApplicationLogo class="!w-[88px]" />
                        </Link>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg p-2 text-gray-700 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 md:hidden"
                            :aria-expanded="mobileNavOpen"
                            aria-controls="marketing-nav-menu"
                            @click="mobileNavOpen = !mobileNavOpen"
                        >
                            <span class="sr-only">
                                {{ mobileNavOpen ? "Close menu" : "Open menu" }}
                            </span>
                            <svg
                                v-show="!mobileNavOpen"
                                class="h-6 w-6"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                            </svg>
                            <svg
                                v-show="mobileNavOpen"
                                class="h-6 w-6"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <nav
                        class="hidden flex-wrap items-center justify-center gap-1 md:flex md:gap-2"
                        aria-label="Primary"
                    >
                        <Link
                            v-for="item in nav"
                            :key="`desktop-${item.href}`"
                            :href="route(item.href)"
                            :aria-current="isNavActive(item.href) ? 'page' : undefined"
                            :class="[
                                'rounded-lg px-3 py-2 text-sm transition',
                                isNavActive(item.href)
                                    ? 'bg-blue-50 font-semibold text-teal-800 ring-1 ring-blue-100/80'
                                    : 'font-medium text-gray-700 hover:bg-blue-50 hover:text-teal-700',
                            ]"
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

                <div
                    v-show="mobileNavOpen"
                    id="marketing-nav-menu"
                    class="-mx-4 border-t border-gray-200 px-4 md:hidden"
                >
                    <div class="flex flex-col gap-0 py-2">
                        <Link
                            v-for="item in nav"
                            :key="`mobile-${item.href}`"
                            :href="route(item.href)"
                            :aria-current="isNavActive(item.href) ? 'page' : undefined"
                            :class="[
                                'block w-full rounded-lg px-4 py-3 text-left text-sm transition',
                                isNavActive(item.href)
                                    ? 'bg-blue-50 font-semibold text-teal-800 ring-1 ring-inset ring-blue-100/80'
                                    : 'font-medium text-gray-700 hover:bg-blue-50 hover:text-teal-700',
                            ]"
                        >
                            {{ item.name }}
                        </Link>
                        <Link
                            :href="route('login')"
                            class="mt-1 inline-flex w-full items-center justify-center rounded-lg bg-gradient-to-r from-blue-600 to-teal-500 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:from-blue-700 hover:to-teal-600"
                        >
                            Log in
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="mt-auto border-t border-gray-200 bg-gray-100">
            <div
                class="mx-auto grid max-w-7xl grid-cols-1 place-items-center gap-6 px-4 py-10 text-center sm:flex sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:text-left"
            >
                <p class="text-sm text-gray-600">
                    &copy; {{ new Date().getFullYear() }} Techiko POS. All
                    rights reserved.
                </p>
                <div
                    class="flex w-full max-w-sm flex-wrap items-center justify-center gap-4 text-sm text-gray-700 sm:w-auto sm:max-w-none sm:justify-end"
                >
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
