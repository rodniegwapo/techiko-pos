<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconUsers } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";

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

const props = defineProps({
  items: Object,
  roles: Array,
});

// Filter state
const search = ref("");
const role = ref(null);

// Modal state
const selectedUser = ref(null);
const showDetailsModal = ref(false);
const showAddModal = ref(false);

// Fetch users
const getItems = () => {
  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      page: 1,
      search: search.value || undefined,
      role: role.value || undefined,
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
  ],
});

// FilterDropdown configuration
const filtersConfig = [
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

// Table composable
const tableFilters = { search, role };
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

// Debug - log the items data
console.log("Users data:", props.items);
console.log("Roles data:", props.roles);
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
          @click="handleAddUser"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <IconPlus />
          </template>
          Add User
        </a-button>
        <FilterDropdown v-model="filters" :filters="filtersConfig" />
      </template>

      <!-- Active Filters -->
      <template #activeFilters>
        <ActiveFilters
          :filters="activeFilters"
          @remove-filter="handleClearSelectedFilter"
          @clear-all="
            () => Object.keys(filters).forEach((k) => (filters[k] = null))
          "
        />
      </template>

      <template #table>
        <UserTable
          :users="items?.data || []"
          :loading="spinning"
          :pagination="pagination"
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
    />
  </AuthenticatedLayout>
</template>
