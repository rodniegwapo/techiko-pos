<script setup>
import { computed, ref } from "vue";
import { router, Head } from "@inertiajs/vue3";
import {
    ArrowLeftOutlined,
    SearchOutlined,
    FilterOutlined,
    DownloadOutlined,
} from "@ant-design/icons-vue";
import { IconShield, IconUsers, IconSettings } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

const props = defineProps({
    roles: Array,
    permissions: Object,
});

// Reactive data
const searchTerm = ref("");
const selectedModule = ref("");

// Computed properties
const totalPermissions = computed(() => {
    return Object.values(props.permissions).reduce(
        (total, modulePermissions) => {
            return total + modulePermissions.length;
        },
        0
    );
});

const filteredPermissions = computed(() => {
    let filtered = { ...props.permissions };

    if (selectedModule.value) {
        filtered = { [selectedModule.value]: filtered[selectedModule.value] };
    }

    if (searchTerm.value) {
        const search = searchTerm.value.toLowerCase();
        const result = {};

        Object.entries(filtered).forEach(([moduleName, permissions]) => {
            const matchingPermissions = permissions.filter(
                (permission) =>
                    getPermissionLabel(permission.name)
                        .toLowerCase()
                        .includes(search) ||
                    permission.name.toLowerCase().includes(search)
            );

            if (matchingPermissions.length > 0) {
                result[moduleName] = matchingPermissions;
            }
        });

        return result;
    }

    return filtered;
});

const moduleOptions = computed(() => {
    return Object.keys(props.permissions).map((module) => ({
        label: module.replace("_", " ").toUpperCase(),
        value: module,
    }));
});

const getRoleColor = (roleName) => {
    const roleColors = {
        "Super Admin": "#f56565",
        Admin: "#ed8936",
        Manager: "#4299e1",
        Supervisor: "#9f7aea",
        Cashier: "#48bb78",
    };
    return roleColors[roleName] || "#a0aec0";
};

// Methods
const handleBack = () => {
    router.visit(route("roles.index"));
};

const hasPermission = (role, permissionId) => {
    return role.permissions?.some((p) => p.id === permissionId) || false;
};

const getModulePermissionCount = (role, moduleName) => {
    if (!role.permissions) return 0;
    return role.permissions.filter((p) => p.module === moduleName).length;
};

const getPermissionLabel = (permissionName) => {
    const parts = permissionName.split(".");
    if (parts.length < 2) return permissionName;

    const action = parts[1];
    const actionLabels = {
        view: "View",
        create: "Create",
        edit: "Edit",
        delete: "Delete",
        apply: "Apply",
        manage: "Manage",
        adjust_points: "Adjust Points",
        export: "Export",
        dashboard: "Dashboard",
        products: "Products",
        movements: "Movements",
        adjustments: "Adjustments",
        locations: "Locations",
        valuation: "Valuation",
        receive: "Receive",
        transfer: "Transfer",
        low_stock: "Low Stock",
        tiers_manage: "Manage Tiers",
        customers_manage: "Manage Customers",
        points_adjust: "Adjust Points",
        reports_view: "View Reports",
    };

    return (
        actionLabels[action] || action.charAt(0).toUpperCase() + action.slice(1)
    );
};
</script>

<template>
    <Head title="Permission Matrix" />

    <AuthenticatedLayout>
        <ContentHeader title="Permission Matrix" />

        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <!-- Header Info -->
            <div class="bg-gray-50 border rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3
                            class="text-2xl font-bold text-gray-900 mb-2 flex items-center"
                        >
                            <IconSettings
                                class="mr-3 text-blue-600"
                                size="28"
                            />
                            Permission Matrix
                        </h3>
                        <p class="text-gray-600">
                            Comprehensive view of role permissions across all
                            system modules
                        </p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a-button type="primary" ghost>
                            <template #icon>
                                <DownloadOutlined />
                            </template>
                            Export
                        </a-button>
                    </div>
                </div>
                <!-- filters -->
                <div class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <a-input
                            v-model:value="searchTerm"
                            placeholder="Search permissions..."
                            size="large"
                            class="w-full"
                        >
                            <template #prefix>
                                <SearchOutlined class="text-gray-400" />
                            </template>
                        </a-input>
                    </div>
                    <div class="md:w-64">
                        <a-select
                            v-model:value="selectedModule"
                            placeholder="Filter by module"
                            size="large"
                            class="w-full"
                            allow-clear
                        >
                            <a-select-option
                                v-for="option in moduleOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </a-select-option>
                        </a-select>
                    </div>
                </div>
            </div>

            <!-- Matrix Table -->
            <div class="bg-white border rounded-lg overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead
                            class="bg-gradient-to-r from-gray-50 to-gray-100"
                        >
                            <tr>
                                <th
                                    class="px-6 py-4 text-left text-sm font-semibold text-gray-700 sticky left-0 bg-gradient-to-r from-gray-50 to-gray-100 z-20 border-r border-gray-200"
                                >
                                    <div class="flex items-center">
                                        <IconShield
                                            class="mr-2 text-gray-600"
                                            size="16"
                                        />
                                        Permission
                                    </div>
                                </th>
                                <th
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="px-4 py-4 text-center text-sm font-semibold text-gray-700 min-w-[140px] border-l border-gray-200"
                                >
                                    <div class="flex flex-col items-center">
                                        <div class="flex items-center mb-1">
                                            <div
                                                class="w-3 h-3 rounded-full mr-2"
                                                :style="{
                                                    backgroundColor:
                                                        getRoleColor(role.name),
                                                }"
                                            ></div>
                                            <span
                                                class="font-bold text-gray-900"
                                                >{{ role.name }}</span
                                            >
                                        </div>
                                        <div
                                            class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full"
                                        >
                                            {{ role.permissions?.length || 0 }}
                                            perms
                                        </div>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <template
                                v-for="(
                                    modulePermissions, moduleName
                                ) in filteredPermissions"
                                :key="moduleName"
                            >
                                <!-- Module Header -->
                                <tr
                                    class="bg-gradient-to-r from-blue-50 to-indigo-50 border-b-2 border-blue-200"
                                >
                                    <td
                                        class="px-6 py-3 font-bold text-blue-900 sticky left-0 bg-gradient-to-r from-blue-50 to-indigo-50 z-10 border-r border-blue-200"
                                    >
                                        <div class="flex items-center">
                                            <div
                                                class="w-2 h-2 bg-blue-500 rounded-full mr-3"
                                            ></div>
                                            {{
                                                moduleName
                                                    .replace("_", " ")
                                                    .toUpperCase()
                                            }}
                                            <span
                                                class="ml-2 text-xs bg-blue-200 text-blue-800 px-2 py-1 rounded-full"
                                            >
                                                {{ modulePermissions.length }}
                                                permissions
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        v-for="role in roles"
                                        :key="`${moduleName}-${role.id}`"
                                        class="px-4 py-3 text-center border-l border-blue-200"
                                    >
                                        <div class="flex flex-col items-center">
                                            <div
                                                class="text-sm font-bold text-blue-700"
                                            >
                                                {{
                                                    getModulePermissionCount(
                                                        role,
                                                        moduleName
                                                    )
                                                }}/{{
                                                    modulePermissions.length
                                                }}
                                            </div>
                                            <div
                                                class="w-16 bg-blue-200 rounded-full h-2 mt-1"
                                            >
                                                <div
                                                    class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                                                    :style="{
                                                        width: `${
                                                            (getModulePermissionCount(
                                                                role,
                                                                moduleName
                                                            ) /
                                                                modulePermissions.length) *
                                                            100
                                                        }%`,
                                                    }"
                                                ></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Individual Permissions -->
                                <tr
                                    v-for="permission in modulePermissions"
                                    :key="permission.id"
                                    class="hover:bg-gray-50 transition-colors duration-150"
                                >
                                    <td
                                        class="px-6 py-3 text-sm text-gray-900 sticky left-0 bg-white z-10 border-r border-gray-200"
                                    >
                                        <div class="flex items-center">
                                            <div
                                                class="w-1.5 h-1.5 bg-gray-400 rounded-full mr-3"
                                            ></div>
                                            <span class="font-medium">{{
                                                getPermissionLabel(
                                                    permission.name
                                                )
                                            }}</span>
                                            <span
                                                class="ml-2 text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded"
                                            >
                                                {{ permission.name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        v-for="role in roles"
                                        :key="`${permission.id}-${role.id}`"
                                        class="px-4 py-3 text-center border-l border-gray-200"
                                    >
                                        <div class="flex justify-center">
                                            <div
                                                v-if="
                                                    hasPermission(
                                                        role,
                                                        permission.id
                                                    )
                                                "
                                                class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center"
                                            >
                                                <span
                                                    class="text-green-600 font-bold text-sm"
                                                    >✓</span
                                                >
                                            </div>
                                            <div
                                                v-else
                                                class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center"
                                            >
                                                <span
                                                    class="text-gray-400 font-bold text-sm"
                                                    >✗</span
                                                >
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div
                    class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-6 text-center shadow-sm"
                >
                    <div class="flex items-center justify-center mb-3">
                        <div
                            class="w-12 h-12 bg-blue-500 rounded-full flex items-center justify-center"
                        >
                            <IconUsers class="text-white" size="24" />
                        </div>
                    </div>
                    <div class="text-3xl font-bold text-blue-600 mb-2">
                        {{ roles.length }}
                    </div>
                    <div class="text-sm text-blue-700 font-medium">
                        Total Roles
                    </div>
                </div>
                <div
                    class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-lg p-6 text-center shadow-sm"
                >
                    <div class="flex items-center justify-center mb-3">
                        <div
                            class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center"
                        >
                            <IconShield class="text-white" size="24" />
                        </div>
                    </div>
                    <div class="text-3xl font-bold text-green-600 mb-2">
                        {{ totalPermissions }}
                    </div>
                    <div class="text-sm text-green-700 font-medium">
                        Total Permissions
                    </div>
                </div>
                <div
                    class="bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200 rounded-lg p-6 text-center shadow-sm"
                >
                    <div class="flex items-center justify-center mb-3">
                        <div
                            class="w-12 h-12 bg-purple-500 rounded-full flex items-center justify-center"
                        >
                            <IconSettings class="text-white" size="24" />
                        </div>
                    </div>
                    <div class="text-3xl font-bold text-purple-600 mb-2">
                        {{ Object.keys(permissions).length }}
                    </div>
                    <div class="text-sm text-purple-700 font-medium">
                        Permission Modules
                    </div>
                </div>
            </div>

            <!-- Legend & Role Summary -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Legend -->
                <div
                    class="bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 rounded-lg p-6"
                >
                    <h4
                        class="font-semibold text-gray-900 mb-4 flex items-center"
                    >
                        <IconSettings class="mr-2 text-gray-600" size="18" />
                        Legend
                    </h4>
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <div
                                class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center mr-3"
                            >
                                <span class="text-green-600 font-bold text-sm"
                                    >✓</span
                                >
                            </div>
                            <span class="text-sm text-gray-700"
                                >Permission granted</span
                            >
                        </div>
                        <div class="flex items-center">
                            <div
                                class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center mr-3"
                            >
                                <span class="text-gray-400 font-bold text-sm"
                                    >✗</span
                                >
                            </div>
                            <span class="text-sm text-gray-700"
                                >Permission not granted</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Role Summary -->
                <div
                    class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm"
                >
                    <h4
                        class="font-semibold text-gray-900 mb-4 flex items-center"
                    >
                        <IconUsers class="mr-2 text-gray-600" size="18" />
                        Role Summary
                    </h4>
                    <div class="space-y-3">
                        <div
                            v-for="role in roles"
                            :key="role.id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                        >
                            <div class="flex items-center">
                                <div
                                    class="w-3 h-3 rounded-full mr-3"
                                    :style="{
                                        backgroundColor: getRoleColor(
                                            role.name
                                        ),
                                    }"
                                ></div>
                                <div>
                                    <h5 class="font-medium text-gray-900">
                                        {{ role.name }}
                                    </h5>
                                    <div class="text-xs text-gray-500">
                                        {{ role.users_count || 0 }} users
                                        assigned
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-bold text-blue-600">
                                    {{ role.permissions?.length || 0 }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    permissions
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-end space-x-4 pt-6 border-t">
                <a-button @click="handleBack">
                    <template #icon>
                        <ArrowLeftOutlined />
                    </template>
                    Back to Roles
                </a-button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
