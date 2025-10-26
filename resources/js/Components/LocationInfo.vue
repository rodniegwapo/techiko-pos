<template>
    <div class="relative inline-block">
        <!-- Info Button -->
        <button
            @click="showModal = true"
            class="inline-flex items-center justify-center w-6 h-6 text-gray-400 hover:text-blue-600 transition-colors duration-200"
            :title="`View location details for ${locationName}`"
        >
            <svg
                class="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
        </button>

        <!-- Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div
                class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0"
            >
                <!-- Background overlay -->
                <div
                    class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    @click="showModal = false"
                ></div>

                <!-- Modal panel -->
                <div
                    class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full"
                >
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div
                                class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10"
                            >
                                <svg
                                    class="h-6 w-6 text-blue-600"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                    />
                                </svg>
                            </div>
                            <div
                                class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full"
                            >
                                <h3
                                    class="text-lg leading-6 font-medium text-gray-900"
                                    id="modal-title"
                                >
                                    Location Information
                                </h3>
                                <div class="mt-4">
                                    <dl
                                        class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2"
                                    >
                                        <div>
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Location Name
                                            </dt>
                                            <dd
                                                class="mt-1 text-sm text-gray-900"
                                            >
                                                {{ locationName }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Type
                                            </dt>
                                            <dd
                                                class="mt-1 text-sm text-gray-900 capitalize"
                                            >
                                                {{ locationType }}
                                            </dd>
                                        </div>
                                        <div
                                            v-if="locationAddress"
                                            class="sm:col-span-2"
                                        >
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Address
                                            </dt>
                                            <dd
                                                class="mt-1 text-sm text-gray-900"
                                            >
                                                {{ locationAddress }}
                                            </dd>
                                        </div>
                                        <div v-if="contactPerson">
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Contact Person
                                            </dt>
                                            <dd
                                                class="mt-1 text-sm text-gray-900"
                                            >
                                                {{ contactPerson }}
                                            </dd>
                                        </div>
                                        <div v-if="phone">
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Phone
                                            </dt>
                                            <dd
                                                class="mt-1 text-sm text-gray-900"
                                            >
                                                {{ phone }}
                                            </dd>
                                        </div>
                                        <div v-if="email">
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Email
                                            </dt>
                                            <dd
                                                class="mt-1 text-sm text-gray-900"
                                            >
                                                {{ email }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Status
                                            </dt>
                                            <dd class="mt-1">
                                                <span
                                                    :class="statusClasses"
                                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                                >
                                                    {{
                                                        isActive
                                                            ? "Active"
                                                            : "Inactive"
                                                    }}
                                                </span>
                                            </dd>
                                        </div>
                                        <div v-if="isDefault">
                                            <dt
                                                class="text-sm font-medium text-gray-500"
                                            >
                                                Default Location
                                            </dt>
                                            <dd class="mt-1">
                                                <span
                                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"
                                                >
                                                    Yes
                                                </span>
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse"
                    >
                        <button
                            type="button"
                            @click="showModal = false"
                            class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from "vue";

const props = defineProps({
    location: {
        type: Object,
        required: true,
    },
});

const showModal = ref(false);

const locationName = computed(() => props.location?.name || "Unknown Location");
const locationType = computed(() => props.location?.type || "unknown");
const locationAddress = computed(() => props.location?.address || null);
const contactPerson = computed(() => props.location?.contact_person || null);
const phone = computed(() => props.location?.phone || null);
const email = computed(() => props.location?.email || null);
const isActive = computed(() => props.location?.is_active ?? true);
const isDefault = computed(() => props.location?.is_default ?? false);

const statusClasses = computed(() => {
    return isActive.value
        ? "bg-green-100 text-green-800"
        : "bg-red-100 text-red-800";
});
</script>
