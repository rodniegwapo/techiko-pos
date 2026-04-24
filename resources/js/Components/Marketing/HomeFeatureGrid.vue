<script setup>
import {
    IconPackage,
    IconShoppingCart,
    IconHeartHandshake,
} from "@tabler/icons-vue";

const features = [
    {
        title: "Fast checkout & discounts",
        text: "Ring up items, apply line and order-level discounts, and keep the line moving when it matters most.",
    },
    {
        title: "Inventory across locations",
        text: "See stock by location, receive and transfer, and keep counts aligned with what you actually sell.",
    },
    {
        title: "Loyalty & reporting",
        text: "Reward repeat visits and open reports when you are ready to review performance—not only at month-end.",
    },
];
</script>

<template>
    <section
        class="border-b border-gray-200 bg-white py-14 md:py-20"
        aria-labelledby="features-heading"
    >
        <div class="mx-auto max-w-6xl px-4">
            <h2
                id="features-heading"
                class="text-2xl font-bold tracking-tight text-gray-800 md:text-3xl"
            >
                Everything your counter needs
            </h2>
            <p class="mt-3 max-w-2xl text-gray-600 leading-relaxed md:text-lg">
                One system for the floor and the back office, so you are not
                switching tabs between tools to finish a day.
            </p>
            <ul
                class="mt-10 grid gap-6 md:grid-cols-3"
            >
                <li
                    v-for="(f, i) in features"
                    :key="f.title"
                    class="flex flex-col rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-md transition hover:border-blue-200 hover:shadow-lg"
                >
                    <div
                        class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-blue-500 shadow-inner"
                    >
                        <IconShoppingCart
                            v-if="i === 0"
                            :size="24"
                            :strokeWidth="1.75"
                            color="white"
                            class="shrink-0"
                        />
                        <IconPackage
                            v-else-if="i === 1"
                            :size="24"
                            :strokeWidth="1.75"
                            color="white"
                            class="shrink-0"
                        />
                        <IconHeartHandshake
                            v-else
                            :size="24"
                            :strokeWidth="1.75"
                            color="white"
                            class="shrink-0"
                        />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800">
                        {{ f.title }}
                    </h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                        {{ f.text }}
                    </p>
                </li>
            </ul>
        </div>
    </section>
</template>
