<script setup>
import { computed } from "vue";
import { router, useForm, Head } from "@inertiajs/vue3";
import { IconArrowLeft, IconWorld, IconMapPin, IconCurrencyDollar, IconClock } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { notification } from "ant-design-vue";

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    timezones: {
        type: Array,
        default: () => [],
    },
    currencies: {
        type: Array,
        default: () => [],
    },
    countries: {
        type: Array,
        default: () => [],
    },
});

// Form handling
const form = useForm({
    name: props.domain.name || '',
    name_slug: props.domain.name_slug || '',
    description: props.domain.description || '',
    country: props.domain.country || '',
    currency: props.domain.currency || '',
    timezone: props.domain.timezone || '',
    is_active: props.domain.is_active ?? true,
});

// Methods
const handleSubmit = () => {
    form.put(route('domains.update', props.domain.id), {
        onSuccess: () => {
            notification.success({
                message: 'Success',
                description: 'Domain updated successfully!',
            });
        },
        onError: (errors) => {
            notification.error({
                message: 'Error',
                description: 'Please check the form for errors.',
            });
        },
    });
};

const handleCancel = () => {
    router.visit(route('domains.show', props.domain.id));
};
</script>

<template>
    <Head :title="`Edit Domain: ${domain.name}`" />

    <AuthenticatedLayout>
        <div class="py-6">
            <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
                <!-- Header Section -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center gap-4">
                            <button
                                @click="handleCancel"
                                class="p-2 text-gray-400 hover:text-gray-600 transition-colors"
                            >
                                <IconArrowLeft class="h-5 w-5" />
                            </button>
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <IconWorld class="h-5 w-5 text-blue-600" />
                                </div>
                                <div>
                                    <h1 class="text-2xl font-bold text-gray-900">Edit Domain</h1>
                                    <p class="text-gray-600">Update domain information</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Section -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="handleSubmit" class="space-y-6">
                            <!-- Domain Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Domain Name *
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="Enter domain name (e.g., Rodnie Store)"
                                    :class="{ 'border-red-500': form.errors.name }"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <!-- Domain Slug -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Domain Slug *
                                </label>
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="form.name_slug"
                                        type="text"
                                        required
                                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono"
                                        placeholder="rodnie-store"
                                        :class="{ 'border-red-500': form.errors.name_slug }"
                                    />
                                    <span class="text-sm text-gray-500">.techiko-pos.com</span>
                                </div>
                                <p v-if="form.errors.name_slug" class="mt-1 text-sm text-red-600">
                                    {{ form.errors.name_slug }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Used in URLs and routing. Changing this may affect existing links.
                                </p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Description
                                </label>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="Brief description of this domain..."
                                    :class="{ 'border-red-500': form.errors.description }"
                                ></textarea>
                                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                                    {{ form.errors.description }}
                                </p>
                            </div>

                            <!-- Country and Currency Row -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Country -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <IconMapPin class="inline h-4 w-4 mr-1" />
                                        Country
                                    </label>
                                    <select
                                        v-model="form.country"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        :class="{ 'border-red-500': form.errors.country }"
                                    >
                                        <option value="">Select Country</option>
                                        <option v-for="country in countries" :key="country" :value="country">
                                            {{ country }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.country" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.country }}
                                    </p>
                                </div>

                                <!-- Currency -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <IconCurrencyDollar class="inline h-4 w-4 mr-1" />
                                        Currency
                                    </label>
                                    <select
                                        v-model="form.currency"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        :class="{ 'border-red-500': form.errors.currency }"
                                    >
                                        <option value="">Select Currency</option>
                                        <option v-for="currency in currencies" :key="currency" :value="currency">
                                            {{ currency }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.currency" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.currency }}
                                    </p>
                                </div>
                            </div>

                            <!-- Timezone -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <IconClock class="inline h-4 w-4 mr-1" />
                                    Timezone
                                </label>
                                <select
                                    v-model="form.timezone"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    :class="{ 'border-red-500': form.errors.timezone }"
                                >
                                    <option value="">Select Timezone</option>
                                    <option v-for="timezone in timezones" :key="timezone" :value="timezone">
                                        {{ timezone }}
                                    </option>
                                </select>
                                <p v-if="form.errors.timezone" class="mt-1 text-sm text-red-600">
                                    {{ form.errors.timezone }}
                                </p>
                            </div>

                            <!-- Active Status -->
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    id="is_active"
                                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                />
                                <label for="is_active" class="text-sm font-medium text-gray-700">
                                    Active Domain
                                </label>
                            </div>
                            <p class="text-xs text-gray-500">
                                Active domains are available for use and can be accessed by users.
                            </p>

                            <!-- Form Actions -->
                            <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-200">
                                <button
                                    type="button"
                                    @click="handleCancel"
                                    class="px-6 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                >
                                    <span v-if="form.processing">Updating...</span>
                                    <span v-else>Update Domain</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
