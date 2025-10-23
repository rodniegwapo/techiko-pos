<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconUsers, IconHierarchy } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import UserTable from "./components/UserTable.vue";
import AddUserModal from "./components/AddUserModal.vue";
import UserDetailsModal from "./components/UserDetailsModal.vue";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();
const { showModal } = useHelpers();
const { hasPermission } = usePermissionsV2();
const { getRoute } = useDomainRoutes();

// Use permission composable
const isSuperUser = computed(
    () => usePage().props.auth?.user?.data?.is_super_user || false
);

const props = defineProps({
    items: Object,
    roles: Array,
    hierarchy: Object,
    domains: Array,
    isGlobalView: Boolean,
});

// Filter state
const search = ref("");
const role = ref(null);
const domain = ref(null);

// Modal state
const selectedUser = ref(null);
const showDetailsModal = ref(false);
const showAddModal = ref(false);
const showHierarchy = ref(false);

// Fetch users
const getItems = () => {
    router.reload({
        only: ["items"],
        preserveScroll: true,
        data: {
            page: 1,
            search: search.value || undefined,
            role: role.value || undefined,
            domain: domain.value || undefined,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, getItems, { debounce: 300 });

// Domain options
const domainOptions = computed(() => 
  (props.domains || []).map(domain => ({ 
    label: domain.name, 
    value: domain.name_slug 
  }))
);

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        {
            label: "Role",
            key: "role",
            ref: role,
            getLabel: toLabel(
                computed(() =>
                    props.roles.map((r) => ({
                        label: r.name,
                        value: r.name,
                    }))
                )
            ),
        },
        ...(props.isGlobalView ? [{
            label: "Domain",
            key: "domain",
            ref: domain,
            getLabel: toLabel(computed(() => domainOptions.value)),
        }] : []),
    ],
});

// FilterDropdown configuration
const filtersConfig = computed(() => {
    const baseConfig = [
        {
            key: "role",
            label: "Role",
            type: "select",
            options: props.roles.map((r) => ({
                label: r.name,
                value: r.name,
            })),
        },
    ];

    // Add domain filter if in global view
    if (props.isGlobalView) {
        baseConfig.push({
            key: "domain",
            label: "Domain",
            type: "select",
            options: domainOptions.value,
        });
    }

    return baseConfig;
});

// Table composable
const tableFilters = computed(() => {
    const baseFilters = { search, role };
    
    // Add domain filter if in global view
    if (props.isGlobalView) {
        baseFilters.domain = domain;
    }
    
    return baseFilters;
});
const { pagination, handleTableChange } = useTable("items", tableFilters);

// Methods
const handleAddUser = () => {
    isEdit.value = false;
    showAddModal.value = true;
};

const handleEditUser = (user) => {
    selectedUser.value = user;
    isEdit.value = true;
    showAddModal.value = true;
};

const handleViewUser = (user) => {
    selectedUser.value = user;
    showDetailsModal.value = true;
};

const handleModalClose = () => {
    showAddModal.value = false;
    showDetailsModal.value = false;
    selectedUser.value = null;
    isEdit.value = false;
};

const handleUserSaved = () => {
    handleModalClose();
    getItems();
};

const handleUserUpdated = (updatedUser) => {
    // Update the selected user with the new data
    selectedUser.value = updatedUser;
    // Refresh the user list to show updated data
    getItems();
};

// Debug - log the items data (remove in production)
// console.log("Users data:", props.items);
// console.log("Roles data:", props.roles);
// console.log("Hierarchy data:", props.hierarchy);

// Hierarchy helper methods
const getRoleColor = (level) => {
    if (level === 1) return "red"; // Grand Manager
    if (level === 2) return "orange"; // Admin
    if (level === 3) return "blue"; // Manager
    if (level === 4) return "cyan"; // Supervisor
    if (level === 5) return "green"; // Cashier
    return "default";
};

const getRoleLabel = (roleName) => {
    const labels = {
        grand_manager: "Grand Manager",
        admin: "Admin",
        manager: "Manager",
        supervisor: "Supervisor",
        cashier: "Cashier",
    };
    return labels[roleName] || roleName;
};

const getRoleColorHex = (level) => {
    if (level === 1) return "#ef4444"; // red
    if (level === 2) return "#f97316"; // orange
    if (level === 3) return "#3b82f6"; // blue
    if (level === 4) return "#06b6d4"; // cyan
    if (level === 5) return "#10b981"; // green
    return "#6b7280"; // gray
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="User Management" />
        <ContentHeader class="mb-8" title="User Management" />

        <ContentLayout title="User Management">
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search users by name or email..."
                    class="min-w-[100px] max-w-[400px]"
                />
                <a-button
                    v-if="isSuperUser || hasPermission('users.store')"
                    @click="handleAddUser"
                    type="primary"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                    Add User
                </a-button>

                <a-button
                    v-if="hasPermission('users.hierarchy')"
                    @click="router.visit(getRoute('users.hierarchy'))"
                    type="default"
                    class="bg-white border flex items-center border-purple-500 text-purple-500"
                >
                    <template #icon>
                        <IconHierarchy />
                    </template>
                    User Hierarchy
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
                <UserTable
                    :users="items?.data || []"
                    :loading="spinning"
                    :pagination="pagination"
                    :hierarchy="hierarchy"
                    :is-global-view="page.props.isGlobalView"
                    @change="handleTableChange"
                    @edit="handleEditUser"
                    @view="handleViewUser"
                />
            </template>
        </ContentLayout>

        <!-- Add/Edit User Modal -->
        <AddUserModal
            :visible="showAddModal"
            :user="selectedUser"
            :is-edit="isEdit"
            :roles="roles"
            @close="handleModalClose"
            @saved="handleUserSaved"
        />

        <!-- User Details Modal -->
        <UserDetailsModal
            :visible="showDetailsModal"
            :user="selectedUser"
            @close="handleModalClose"
            @edit="handleEditUser"
            @userUpdated="handleUserUpdated"
        />
    </AuthenticatedLayout>
</template>
