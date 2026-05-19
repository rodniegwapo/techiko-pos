<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { message } from "ant-design-vue";

const page = usePage();
const fileInput = ref(null);

const form = useForm({
    image: null,
});

const flash = computed(() => page.props.flash ?? {});

watch(
    () => flash.value?.success,
    (msg) => {
        if (msg) {
            message.success(msg);
        }
    },
    { immediate: true },
);

watch(
    () => flash.value?.error,
    (msg) => {
        if (msg) {
            message.error(msg);
        }
    },
    { immediate: true },
);

function onFileChange(event) {
    const file = event.target.files?.[0] ?? null;
    form.image = file;
}

function submit() {
    form.post(route("dev.s3-upload-test.store"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset("image");
            if (fileInput.value) {
                fileInput.value.value = "";
            }
        },
    });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="S3 upload test" />

        <ContentHeader class="mb-6" title="S3 upload test" />

        <ContentLayout title="Test image upload">
            <template #table>
                <div class="px-6 pt-2 pb-6 max-w-2xl space-y-6">
                    <p class="text-sm text-gray-600">
                        Development-only upload to the configured
                        <code class="text-xs">s3</code> disk (
                        <code class="text-xs">test-uploads/</code> prefix).
                        Requires valid
                        <code class="text-xs">AWS_*</code> in
                        <code class="text-xs">.env</code>.
                    </p>

                    <p>
                        <Link
                            :href="route('dashboard')"
                            class="text-sm text-indigo-600 hover:text-indigo-800"
                        >
                            ← Back to dashboard
                        </Link>
                    </p>

                    <form class="space-y-4" @submit.prevent="submit">
                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                                for="s3-test-image"
                            >
                                Image (max 5 MB)
                            </label>
                            <input
                                id="s3-test-image"
                                ref="fileInput"
                                type="file"
                                accept="image/*"
                                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                @change="onFileChange"
                            />
                            <p
                                v-if="form.errors.image"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ form.errors.image }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                            :disabled="form.processing || !form.image"
                        >
                            Upload to S3
                        </button>
                    </form>

                    <div
                        v-if="flash.s3_path || flash.s3_url"
                        class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-2 text-sm"
                    >
                        <p v-if="flash.s3_path" class="break-all">
                            <span class="font-medium text-gray-700"
                                >Path:</span
                            >
                            {{ flash.s3_path }}
                        </p>
                        <p v-if="flash.s3_url" class="break-all">
                            <span class="font-medium text-gray-700"
                                >URL (temporary or public):</span
                            >
                            <a
                                :href="flash.s3_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-1 text-indigo-600 hover:text-indigo-800"
                            >
                                {{ flash.s3_url }}
                            </a>
                        </p>
                        <p
                            v-if="flash.s3_path && !flash.s3_url"
                            class="text-amber-800"
                        >
                            File was stored, but no preview URL could be
                            generated. Check
                            <code class="text-xs">AWS_URL</code> and bucket
                            policy, or use a temporary URL–capable disk
                            configuration.
                        </p>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
