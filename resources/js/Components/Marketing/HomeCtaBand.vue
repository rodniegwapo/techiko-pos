<script setup>
import { Link } from "@inertiajs/vue3";
</script>

<template>
    <section
        class="border-b border-slate-200/80 py-16 md:py-20"
        aria-labelledby="cta-heading"
    >
        <div
            class="mx-auto max-w-6xl rounded-2xl border border-slate-200/60 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 px-6 py-12 text-center shadow-xl md:px-10 md:py-16"
        >
            <h2
                id="cta-heading"
                class="text-2xl font-bold tracking-tight text-white md:text-3xl"
            >
                Ready to run your counter?
            </h2>
            <p
                class="mx-auto mt-3 max-w-xl text-slate-300 text-base leading-relaxed md:text-lg"
            >
                Open an account to explore Techiko POS, or sign in if your team
                is already on board. See
                <Link
                    :href="route('marketing.pricing')"
                    class="font-semibold text-white underline decoration-teal-400/80 decoration-2 underline-offset-2 transition hover:decoration-teal-300"
                >pricing &amp; plans</Link>
                for how to get started.
            </p>
            <div
                class="mt-8 flex flex-wrap items-center justify-center gap-3"
            >
                <Link
                    :href="route('register')"
                    class="inline-flex items-center justify-center rounded-xl bg-white px-5 py-3 text-base font-semibold text-slate-900 shadow transition hover:bg-slate-100"
                >
                    Create account
                </Link>
                <Link
                    :href="route('login')"
                    class="inline-flex items-center justify-center rounded-xl border-2 border-white/40 bg-transparent px-5 py-3 text-base font-semibold text-white transition hover:border-white/70 hover:bg-white/10"
                >
                    Log in
                </Link>
            </div>
        </div>
    </section>
</template>
