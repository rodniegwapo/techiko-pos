<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconWorld } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import DomainTable from "./components/DomainTable.vue";
import CreateDomainModal from "./components/CreateDomainModal.vue";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();
const { showModal } = useHelpers();
const { hasPermission } = usePermissionsV2();

const props = defineProps({
    domains: {
        type: Object,
        default: () => ({ data: [] }),
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

// Debug props
console.log("Index.vue props:", {
    timezones: props.timezones,
    currencies: props.currencies,
    countries: props.countries,
});

// Filter state
const search = ref("");
const country = ref(null);
const status = ref(null);

// Modal state
const showCreateModal = ref(false);
const selectedDomain = ref(null);

// Fetch domains
const getItems = () => {
    router.reload({
        only: ["domains"],
        preserveScroll: true,
        data: {
            page: 1,
            search: search.value || undefined,
            country: country.value || undefined,
            status: status.value || undefined,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, getItems, { debounce: 300 });

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        {
            label: "Country",
            key: "country",
            ref: country,
            getLabel: toLabel(
                computed(() => {
                    try {
                        return Array.isArray(props.countries)
                            ? props.countries.map((c) => ({
                                  label: c.name || c,
                                  value: c.name || c,
                              }))
                            : [];
                    } catch (error) {
                        console.warn("Error creating country options:", error);
                        return [];
                    }
                })
            ),
        },
        {
            label: "Status",
            key: "status",
            ref: status,
            getLabel: toLabel(
                computed(() => [
                    { label: "Active", value: "active" },
                    { label: "Inactive", value: "inactive" },
                ])
            ),
        },
    ],
});

// FilterDropdown configuration
const filtersConfig = computed(() => {
    try {
        return [
            {
                key: "country",
                label: "Country",
                type: "select",
                options: Array.isArray(props.countries)
                    ? props.countries.map((c) => ({
                          label: c.name || c,
                          value: c.name || c,
                      }))
                    : [],
            },
            {
                key: "status",
                label: "Status",
                type: "select",
                options: [
                    { label: "Active", value: "active" },
                    { label: "Inactive", value: "inactive" },
                ],
            },
        ];
    } catch (error) {
        console.warn("Error creating filters config:", error);
        return [];
    }
});

// Table composable
const tableFilters = { search, country, status };
const { pagination, handleTableChange } = useTable("domains", tableFilters);

// Methods
const handleCreate = () => {
    isEdit.value = false;
    showCreateModal.value = true;
};

const handleEdit = (domain) => {
    selectedDomain.value = domain;
    isEdit.value = true;
    showCreateModal.value = true;
};

const handleView = (domain) => {
    router.visit(route("domains.show", domain.id));
};

const handleDelete = (domain) => {
    if (
        confirm(
            `Are you sure you want to delete "${domain.name}"? This action cannot be undone.`
        )
    ) {
        router.delete(route("domains.destroy", domain.id), {
            onStart: () => (spinning.value = true),
            onSuccess: () => {
                notification.success({
                    message: "Success",
                    description: "Domain deleted successfully!",
                });
            },
            onError: (errors) => {
                notification.error({
                    message: "Error",
                    description: "Failed to delete domain.",
                });
            },
            onFinish: () => (spinning.value = false),
        });
    }
};

const handleToggle = (domain) => {
    router.post(
        route("domains.toggle-status", {
            domain: domain.name_slug,
        }),
        {},
        {
            preserveScroll: true,
            onStart: () => (spinning.value = true),
            onSuccess: () => {
                getItems(); // Refresh the list
            },
            onError: () => {
                // Optionally show error notification
            },
            onFinish: () => (spinning.value = false),
        }
    );
};

const handleModalClose = () => {
    showCreateModal.value = false;
    selectedDomain.value = null;
    isEdit.value = false;
};

const handleModalSuccess = () => {
    handleModalClose();
    getItems();
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Domain Management" />
        <ContentHeader class="mb-8" title="Domain Management" />

        <ContentLayout title="Domain Management">
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search domains by name, slug, or description..."
                    class="min-w-[100px] max-w-[400px]"
                />
                <a-button
                    v-if="hasPermission('domains.store')"
                    @click="handleCreate"
                    type="primary"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                    Add Domain
                </a-button>

                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <!-- Active Filters -->
            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="
                        () =>
                            Object.keys(filters).forEach(
                                (k) => (filters[k] = null)
                            )
                    "
                />
            </template>

            <template #table>
                <DomainTable
                    :domains="domains?.data || []"
                    :loading="spinning"
                    :pagination="pagination"
                    @change="handleTableChange"
                    @edit="handleEdit"
                    @view="handleView"
                    @delete="handleDelete"
                    @toggle="handleToggle"
                />
            </template>
        </ContentLayout>

        <!-- Add/Edit Domain Modal -->
        <CreateDomainModal
            :visible="showCreateModal"
            :domain="selectedDomain"
            :is-edit="isEdit"
            :timezones="timezones"
            :currencies="currencies"
            :countries="countries"
            @close="handleModalClose"
            @success="handleModalSuccess"
        />
    </AuthenticatedLayout>
</template>
