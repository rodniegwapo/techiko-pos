<script setup>
import { computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { IconLock } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

/** What a locked organization's users see: a super admin put its subscription on hold for not paying. */
const props = defineProps({
    organization: { type: String, required: true },
    endedOn: { type: String, default: null },
    contactUrl: { type: String, required: true },
});

const ended = computed(() => (props.endedOn ? dayjs(props.endedOn).format("MMMM D, YYYY") : null));
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Subscription on hold" />
        <div class="mx-auto mt-10 max-w-lg px-4">
            <section class="rounded-lg border border-red-100 bg-white px-6 py-8 text-center shadow-sm" data-testid="subscription-on-hold">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
                    <IconLock :size="26" />
                </div>
                <h1 class="text-xl font-bold text-gray-900">Your subscription is on hold</h1>
                <p class="mt-2 text-sm text-gray-600">
                    {{ organization }}'s pages are paused until the subscription is renewed.
                    <template v-if="ended">It ended on {{ ended }}.</template>
                    Your data is safe and nothing has been deleted.
                </p>
                <p class="mt-1 text-sm text-gray-600">Sales rung up offline will still upload.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-2">
                    <a :href="contactUrl">
                        <a-button type="primary">Contact us to renew</a-button>
                    </a>
                    <a-button @click="router.post(route('logout'))">Log out</a-button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
