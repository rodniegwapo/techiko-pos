<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import {
    PlusOutlined,
    EditOutlined,
    DeleteOutlined,
    EyeOutlined,
    SettingOutlined,
    PlusSquareOutlined,
} from "@ant-design/icons-vue";
import {
    IconBuilding,
    IconBuildingWarehouse,
    IconTruck,
    IconUser,
    IconWorld,
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { watchDebounced } from "@vueuse/core";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useTable } from "@/Composables/useTable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
const { showModal, showConfirm } = useHelpers();
const { spinning } = useGlobalVariables();
const { getRoute } = useDomainRoutes();

const search = ref("");
const type = ref(null);
const status = ref(null);
const domain = ref(null);

// Props from backend
const props = defineProps({
    locations: Object,
    filters: Object,
    locationTypes: Array,
    domains: Array,
    isGlobalView: Boolean,
});

// Initialize filters from backend
onMounted(() => {
    if (props.filters) {
        search.value = props.filters.search || "";
        type.value = props.filters.type || null;
        status.value = props.filters.status || null;
        domain.value = props.filters.domain || null;
    }
});

// Fetch items
const getItems = () => {
    router.reload({
        only: ["locations"],
        preserveScroll: true,
        data: {
            search: search.value || undefined,
            type: type.value || undefined,
            status: status.value || undefined,
            domain: domain.value || undefined,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Filter options
const statusOptions = computed(() => [
    { label: "Active", value: "active" },
    { label: "Inactive", value: "inactive" },
]);

const domainOptions = computed(() => {
    const options = (props.domains || []).map((domain) => ({
        label: domain.name,
        value: domain.name_slug,
    }));
    return options;
});

// Filter management
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        {
            label: "Type",
            key: "type",
            ref: type,
            getLabel: toLabel(computed(() => props.locationTypes)),
        },
        {
            label: "Status",
            key: "status",
            ref: status,
            getLabel: toLabel(computed(() => statusOptions.value)),
        },
        ...(page.props.auth?.user?.data?.is_super_user
            ? [
                  {
                      label: "Domain",
                      key: "domain",
                      ref: domain,
                      getLabel: toLabel(computed(() => domainOptions.value)),
                  },
              ]
            : []),
    ],
});

// FilterDropdown configuration
const filtersConfig = computed(() => {
    const baseConfig = [
        {
            key: "type",
            label: "Type",
            type: "select",
            options: props.locationTypes,
        },
        {
            key: "status",
            label: "Status",
            type: "select",
            options: statusOptions.value,
        },
    ];

    // Add domain filter for super users only
    if (page.props.auth?.user?.data?.is_super_user) {
        baseConfig.push({
            key: "domain",
            label: "Domain",
            type: "select",
            options: domainOptions.value,
        });
    }

    return baseConfig;
});

// Group filters in one object
const tableFilters = computed(() => {
    const baseFilters = { search, type, status };

    // Add domain filter for super users only
    if (page.props.auth?.user?.data?.is_super_user) {
        baseFilters.domain = domain;
    }

    return baseFilters;
});

// Table management
const { pagination, handleTableChange } = useTable("locations", tableFilters);

// Methods
const createLocation = () => {
    router.visit(getRoute("inventory.locations.create"));
};

const viewLocation = (location) => {
    router.visit(getRoute("inventory.locations.show", { location: location.id }));
};

const editLocation = (location) => {
    router.visit(getRoute("inventory.locations.edit", { location: location.id }));
};

const deleteLocation = (location) => {
    showConfirm({
        title: "Delete Location",
        content: `Are you sure you want to delete "${location.name}"?`,
        onOk: () => {
            router.delete(getRoute("inventory.locations.destroy", { location: location.id }), {
                onSuccess: () => {
                    // Success handled by redirect
                },
            });
        },
    });
};

const setAsDefault = (location) => {
    router.post(
        route("inventory.locations.set-default", location.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                getItems();
            },
        }
    );
};

const toggleStatus = (location) => {
    router.post(
        route("inventory.locations.toggle-status", location.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                getItems();
            },
        }
    );
};

// Get icon for location type
const getTypeIcon = (type) => {
    switch (type) {
        case "store":
            return IconBuilding;
        case "warehouse":
            return IconBuildingWarehouse;
        case "supplier":
            return IconTruck;
        case "customer":
            return IconUser;
        default:
            return IconBuilding;
    }
};

// Table columns - simplified like InventoryProductTable
const columns = computed(() => {
    const baseColumns = [
        { title: "Location", dataIndex: "name", key: "name", align: "left" },
        { title: "Type", dataIndex: "type", key: "type", align: "left" },
        {
            title: "Address",
            dataIndex: "address",
            key: "address",
            align: "left",
        },
        { title: "Contact", key: "contact", align: "left" },
        { title: "Products", key: "products", align: "left" },
        { title: "Status", key: "status", align: "left" },
    ];

    // Add domain column for super users only
    if (page.props.auth?.user?.data?.is_super_user) {
        baseColumns.splice(1, 0, {
            title: "Domain",
            dataIndex: "domain",
            key: "domain",
            align: "left",
        });
    }

    baseColumns.push({
        title: "Actions",
        key: "actions",
        align: "center",
        width: "1%",
    });

    return baseColumns;
});
</script>

<template>
    <Head title="Inventory Locations" />

    <AuthenticatedLayout>
        <ContentHeader title="Inventory Locations" />

        <ContentLayout title="Inventory Locations">
            <!-- Filters -->
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search locations..."
                    class="min-w-[100px] max-w-[300px]"
                />
                <a-button
                    type="primary"
                    @click="createLocation"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                >
                    <template #icon>
                        <PlusSquareOutlined />
                    </template>
                    Add Location
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

            <template #activeStore>
                <LocationInfoAlert />
            </template>

            <!-- Table -->
            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="locations.data"
                    :pagination="pagination"
                    :loading="spinning"
                    @change="handleTableChange"
                    row-key="id"
                >
                    <!-- Name column -->
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'name'">
                            <div class="flex items-center space-x-3">
                                <!-- Location Icon -->
                                <div
                                    class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center"
                                >
                                    <component
                                        :is="getTypeIcon(record.type)"
                                        class="text-blue-600"
                                        :size="20"
                                    />
                                </div>

                                <!-- Location Info -->
                                <div>
                                    <div class="font-semibold text-gray-900">
                                        {{ record.name }}
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="text-sm text-gray-500">
                                            {{ record.code }}
                                        </div>
                                        <a-tag
                                            v-if="record.is_default"
                                            color="processing"
                                            size="small"
                                            >Default</a-tag
                                        >
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Domain column -->
                        <template v-else-if="column.key === 'domain'">
                            <div class="flex items-center gap-2">
                                <IconWorld size="16" class="text-blue-500" />
                                <span class="text-sm font-medium">{{
                                    record.domain || "N/A"
                                }}</span>
                            </div>
                        </template>

                        <!-- Type column -->
                        <template v-else-if="column.key === 'type'">
                            <a-tag
                                class="w-fit"
                                :color="record.type_badge.color"
                            >
                                {{ record.type_badge.text }}
                            </a-tag>
                        </template>

                        <!-- Address column -->
                        <template v-else-if="column.key === 'address'">
                            <div
                                v-if="record.address"
                                class="text-sm text-gray-600"
                            >
                                {{ record.address }}
                            </div>
                            <span v-else class="text-gray-400 text-sm"
                                >No address</span
                            >
                        </template>

                        <!-- Contact column -->
                        <template v-else-if="column.key === 'contact'">
                            <div class="text-sm">
                                <div
                                    v-if="record.contact_person"
                                    class="font-medium text-gray-900"
                                >
                                    {{ record.contact_person }}
                                </div>
                                <div v-if="record.phone" class="text-gray-600">
                                    {{ record.phone }}
                                </div>
                                <div v-if="record.email" class="text-gray-600">
                                    {{ record.email }}
                                </div>
                                <span
                                    v-if="
                                        !record.contact_person &&
                                        !record.phone &&
                                        !record.email
                                    "
                                    class="text-gray-400"
                                    >No contact info</span
                                >
                            </div>
                        </template>

                        <!-- Products column -->
                        <template v-else-if="column.key === 'products'">
                            <div class="flex items-center gap-2">
                                <div class="font-semibold text-lg">
                                    {{ record.product_inventories_count || 0 }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    products
                                </div>
                            </div>
                        </template>

                        <!-- Status column -->
                        <template v-else-if="column.key === 'status'">
                            <a-tag
                                class="w-fit"
                                :color="record.status_badge.color"
                            >
                                {{ record.status_badge.text }}
                            </a-tag>
                        </template>

                        <!-- Actions column -->
                        <template v-else-if="column.key === 'actions'">
                            <div class="flex justify-center space-x-1">
                                <IconTooltipButton
                                    name="View Details"
                                    @click="viewLocation(record)"
                                >
                                    <EyeOutlined :size="20" class="mx-auto" />
                                </IconTooltipButton>

                                <IconTooltipButton
                                    name="Edit Location"
                                    @click="editLocation(record)"
                                >
                                    <EditOutlined :size="20" class="mx-auto" />
                                </IconTooltipButton>

                                <a-dropdown>
                                    <IconTooltipButton name="More Actions">
                                        <SettingOutlined
                                            :size="20"
                                            class="mx-auto"
                                        />
                                    </IconTooltipButton>
                                    <template #overlay>
                                        <a-menu>
                                            <a-menu-item
                                                v-if="!record.is_default"
                                                key="default"
                                                @click="setAsDefault(record)"
                                            >
                                                Set as Default
                                            </a-menu-item>
                                            <a-menu-item
                                                key="toggle"
                                                @click="toggleStatus(record)"
                                            >
                                                {{
                                                    record.is_active
                                                        ? "Deactivate"
                                                        : "Activate"
                                                }}
                                            </a-menu-item>
                                            <a-menu-divider />
                                            <a-menu-item
                                                key="delete"
                                                danger
                                                :disabled="
                                                    record.is_default ||
                                                    record.product_inventories_count >
                                                        0
                                                "
                                                @click="deleteLocation(record)"
                                            >
                                                Delete
                                            </a-menu-item>
                                        </a-menu>
                                    </template>
                                </a-dropdown>
                            </div>
                        </template>
                    </template>

                    <!-- Empty State -->
                    <template #emptyText>
                        <div class="text-center py-8">
                            <IconBuilding
                                :size="48"
                                class="mx-auto text-gray-400 mb-4"
                            />
                            <p class="text-gray-500">No locations found</p>
                            <p class="text-sm text-gray-400">
                                Try adjusting your filters or create a new
                                location
                            </p>
                        </div>
                    </template>
                </a-table>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
