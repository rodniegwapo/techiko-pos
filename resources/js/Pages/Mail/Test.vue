<script setup>
import { watch } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { message } from "ant-design-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";

const page = usePage();

const form = useForm({
    email: "",
});

watch(
    () => [page.props.flash?.success, page.props.flash?.error],
    ([success, error]) => {
        if (success) {
            message.success(success);
        }
        if (error) {
            message.error(error);
        }
    },
    { immediate: true },
);

function submit() {
    form.post(route("mail.test.send"), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Test email" />
        <ContentHeader class="mb-6" title="Test email" />
        <ContentLayout title="Send a test">
            <template #table>
                <div class="px-6 pt-2 pb-6">
                    <div class="max-w-xl space-y-4">
                        <p class="mb-0 text-sm leading-relaxed text-gray-600">
                            Sends one message now through the current mailer
                            (not the queue). Use this to confirm outgoing mail
                            is working.
                        </p>
                        <a-form layout="vertical" @submit.prevent="submit">
                            <a-form-item
                                label="Recipient email"
                                :validate-status="form.errors.email ? 'error' : ''"
                                :help="form.errors.email"
                            >
                                <a-input
                                    v-model:value="form.email"
                                    type="email"
                                    placeholder="you@example.com"
                                    autocomplete="email"
                                    size="large"
                                />
                            </a-form-item>
                            <a-form-item>
                                <a-button
                                    type="primary"
                                    html-type="submit"
                                    size="large"
                                    :loading="form.processing"
                                    :disabled="form.processing"
                                >
                                    Send test email
                                </a-button>
                            </a-form-item>
                        </a-form>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
