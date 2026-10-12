<script setup>
import { computed, reactive, watch } from "vue";
import { router, Head, usePage } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import {
    IconEdit,
    IconArrowLeft,
    IconMapPin,
    IconUsers,
    IconReportAnalytics,
    IconSparkles,
} from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const page = usePage();

// The domain arrives as a resource ({ data: … }).
const domain = computed(() => props.domain?.data ?? props.domain);
const isSuperUser = computed(() => !!page.props.auth?.user?.data?.is_super_user);

const formatDate = (dateString) => {
    if (!dateString) return "-";
    return new Date(dateString).toLocaleDateString("en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
};

const regional = computed(() => [
    { label: "Country", value: domain.value.country_code },
    { label: "Currency", value: domain.value.currency_code },
    { label: "Timezone", value: domain.value.timezone },
    { label: "Date format", value: domain.value.date_format },
    { label: "Time format", value: domain.value.time_format },
    { label: "Language", value: domain.value.language_code },
]);

/*** === FEATURES (super admins turn paid features on per organization) === ***/
const savedFeatures = () => ({
    finance: domain.value.settings?.features?.finance === true,
    ai:
        domain.value.settings?.features?.finance === true &&
        domain.value.settings?.features?.ai === true,
});

const features = reactive({ ...savedFeatures(), saving: false });

// AI support lives inside Finance, so it goes off with it.
watch(
    () => features.finance,
    (on) => {
        if (!on) features.ai = false;
    },
);

const featuresChanged = computed(() => {
    const saved = savedFeatures();
    return saved.finance !== features.finance || saved.ai !== features.ai;
});

const saveFeatures = () => {
    features.saving = true;
    router.patch(
        route("domains.features.update", { domain: domain.value.name_slug }),
        { finance: features.finance, ai: features.ai },
        {
            preserveScroll: true,
            onSuccess: () => {
                Object.assign(features, savedFeatures());
                notification.success({ message: "Features updated" });
            },
            onError: () => notification.error({ message: "Couldn't save the features" }),
            onFinish: () => (features.saving = false),
        },
    );
};

// Methods
const handleEdit = () => {
    router.visit(route("domains.edit", { domain: domain.value.name_slug }));
};

const handleBack = () => {
    router.visit(route("domains.index"));
};
</script>

<template>
    <Head :title="`Organization: ${domain.name}`" />

    <AuthenticatedLayout>
        <div class="w-full min-w-0">
            <ContentHeader class="mb-6" :title="domain.name">
                <template #actions>
                    <a-button @click="handleBack">
                        <template #icon><IconArrowLeft :size="16" class="mr-1 inline" /></template>
                        Back
                    </a-button>
                    <a-button type="primary" @click="handleEdit">
                        <template #icon><IconEdit :size="16" class="mr-1 inline" /></template>
                        Edit
                    </a-button>
                </template>
            </ContentHeader>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main column -->
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <!-- Basic information -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 text-lg font-semibold text-gray-900">Basic information</h2>
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm text-gray-500">Name</dt>
                                <dd class="font-medium text-gray-900">{{ domain.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Slug</dt>
                                <dd class="font-mono text-gray-900">{{ domain.name_slug }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Status</dt>
                                <dd class="flex">
                                    <a-tag :color="domain.is_active ? 'green' : 'red'">
                                        {{ domain.is_active ? "Active" : "Inactive" }}
                                    </a-tag>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Created</dt>
                                <dd class="text-gray-900">{{ formatDate(domain.created_at) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Regional settings -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
                            <IconMapPin :size="20" /> Regional settings
                        </h2>
                        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <div v-for="item in regional" :key="item.label">
                                <dt class="text-sm text-gray-500">{{ item.label }}</dt>
                                <dd class="text-gray-900">{{ item.value || "Not set" }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Users -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
                            <IconUsers :size="20" /> Users ({{ domain.users?.length || 0 }})
                        </h2>
                        <div v-if="domain.users?.length" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div
                                v-for="user in domain.users"
                                :key="user.id"
                                class="flex items-center gap-3 rounded-lg bg-gray-50 p-3"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100">
                                    <span class="text-sm font-semibold text-blue-600">
                                        {{ (user.name || "?").charAt(0).toUpperCase() }}
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-gray-900">{{ user.name }}</p>
                                    <p class="truncate text-sm text-gray-500">{{ user.email }}</p>
                                </div>
                                <a-tag :color="user.is_super_user ? 'purple' : 'green'">
                                    {{ user.is_super_user ? "Super user" : "User" }}
                                </a-tag>
                            </div>
                        </div>
                        <div v-else class="py-8 text-center text-gray-500">
                            <IconUsers class="mx-auto mb-2 h-12 w-12 text-gray-300" />
                            <p>No users in this organization yet</p>
                        </div>
                    </section>
                </div>

                <!-- Side column -->
                <div class="min-w-0 space-y-6">
                    <!-- Features (paid add-ons; off until a super admin turns them on) -->
                    <section
                        v-if="isSuperUser"
                        class="rounded-lg bg-white p-6"
                        data-testid="domain-features"
                    >
                        <h2 class="mb-1 text-lg font-semibold text-gray-900">Features</h2>
                        <p class="mb-4 text-sm text-gray-500">
                            Turned-off features are hidden from this organization and closed to it.
                        </p>

                        <div class="space-y-4">
                            <a-checkbox
                                v-model:checked="features.finance"
                                class="!ml-0 flex items-start"
                                data-testid="feature-finance"
                            >
                                <span class="flex items-center gap-1 font-medium">
                                    <IconReportAnalytics :size="16" /> Finance
                                </span>
                                <span class="block text-xs text-gray-500">
                                    Finance dashboard, statements, expenses, payables, VAT report
                                    and monthly reviews.
                                </span>
                            </a-checkbox>

                            <a-checkbox
                                v-model:checked="features.ai"
                                :disabled="!features.finance"
                                class="!ml-0 flex items-start"
                                data-testid="feature-ai"
                            >
                                <span class="flex items-center gap-1 font-medium">
                                    <IconSparkles :size="16" /> AI support
                                </span>
                                <span class="block text-xs text-gray-500">
                                    {{
                                        features.finance
                                            ? "\"Explain this\", \"Ask about your business\" and AI summaries in monthly reviews."
                                            : "Turn on Finance first."
                                    }}
                                </span>
                            </a-checkbox>
                        </div>

                        <a-button
                            type="primary"
                            class="mt-5 w-full"
                            :loading="features.saving"
                            :disabled="!featuresChanged"
                            data-testid="save-features"
                            @click="saveFeatures"
                        >
                            Save features
                        </a-button>
                    </section>

                    <!-- Dates -->
                    <section class="rounded-lg bg-white p-6">
                        <h2 class="mb-4 text-lg font-semibold text-gray-900">Activity</h2>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Created</dt>
                                <dd class="text-right text-gray-900">{{ formatDate(domain.created_at) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Updated</dt>
                                <dd class="text-right text-gray-900">{{ formatDate(domain.updated_at) }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
