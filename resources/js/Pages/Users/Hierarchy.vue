<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import {
    IconUser,
    IconUsers,
    IconUserCheck,
    IconShield,
    IconSettings,
    IconHierarchy,
    IconArrowUp,
} from "@tabler/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import * as d3 from "d3";
import { OrgChart } from "d3-org-chart";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const { spinning } = useGlobalVariables();
const isSuperUser = computed(
    () => usePage().props.auth?.user?.data?.is_super_user || false
);

const props = defineProps({
    users: Array,
    hierarchy: Object,
});

const autoAssignLoading = ref(false);
const chartContainer = ref(null);
const orgChartInstance = ref(null);

const getUserRole = (user) => user.roles?.[0]?.name || "No Role";

const topLevelUsers = computed(() => {
    if (!props.users) return [];
    const superUsers = props.users.filter((u) => u.is_super_user);
    if (superUsers.length) return superUsers;
    const admins = props.users.filter(
        (u) => getUserRole(u) === "admin" && !u.supervisor_id
    );
    if (admins.length) return admins;
    return props.users.filter((u) => !u.supervisor_id);
});

const getSubordinates = (id) => {
    if (!props.users) return [];
    const user = props.users.find((u) => u.id === id);
    if (!user) return [];
    if (user.is_super_user) {
        return props.users.filter(
            (u) =>
                !u.is_super_user &&
                (u.supervisor_id === id ||
                    (getUserRole(u) === "admin" && !u.supervisor_id))
        );
    }
    return props.users.filter(
        (u) => u.supervisor_id === id && !u.is_super_user
    );
};

// Transform your data to D3 org chart format with role-based hierarchy
const chartData = computed(() => {
    if (!props.users || topLevelUsers.value.length === 0) return null;

    const flatData = [];

    // Find the Super Admin
    const superAdmin = props.users.find((user) => user.is_super_user);

    if (superAdmin) {
        // Super Admin is the root
        flatData.push({
            id: String(superAdmin.id),
            name: superAdmin.name,
            title: "Super User",
            parentId: null,
            email: superAdmin.email,
            status: superAdmin.status,
            isSuperUser: true,
            isAdmin: false,
        });

        // Define role hierarchy levels
        const roleHierarchy = {
            admin: 1,
            manager: 2,
            supervisor: 3,
            cashier: 4,
        };

        // Group users by role
        const usersByRole = {
            admin: [],
            manager: [],
            supervisor: [],
            cashier: [],
        };

        // Categorize users by role
        props.users.forEach((user) => {
            if (user.id === superAdmin.id) return; // Skip super admin

            const role = getUserRole(user);
            if (usersByRole[role]) {
                usersByRole[role].push(user);
            }
        });

        // Add all users with their actual supervisor relationships
        props.users.forEach((user) => {
            if (user.id === superAdmin.id) return; // Skip super admin (already added)

            const role = getUserRole(user);
            let parentId = String(superAdmin.id); // Default to super admin

            // If user has a specific supervisor, use that
            if (user.supervisor_id) {
                parentId = String(user.supervisor_id);
            }
            // Admins without supervisor report to Super Admin
            else if (role === "admin") {
                parentId = String(superAdmin.id);
            }

            flatData.push({
                id: String(user.id),
                name: user.name,
                title: role,
                email: user.email,
                status: user.status,
                isSuperUser: false,
                isAdmin: role === "admin",
                parentId: parentId,
            });
        });
    } else {
        // Fallback: no super admin found, use organization root
        flatData.push({
            id: "root",
            name: "Organization",
            title: "Root",
            parentId: null,
            email: "",
            status: "active",
            isSuperUser: false,
            isAdmin: false,
        });

        // Add all users
        props.users.forEach((user) => {
            const role = getUserRole(user);
            const isSuper = user.is_super_user;

            let parentId = "root";

            if (user.supervisor_id) {
                parentId = String(user.supervisor_id);
            }

            flatData.push({
                id: String(user.id),
                name: user.name,
                title: isSuper ? "Super User" : role,
                email: user.email,
                status: user.status,
                isSuperUser: isSuper,
                isAdmin: role === "admin",
                parentId: parentId,
            });
        });
    }

    return flatData;
});

// Chart utility functions
const exportChart = () => {
    if (orgChartInstance.value) {
        try {
            orgChartInstance.value.exportImg();
        } catch (err) {}
    }
};

const resetZoom = () => {
    if (orgChartInstance.value) {
        try {
            // Try different zoom reset methods
            if (typeof orgChartInstance.value.zoomToFit === "function") {
                orgChartInstance.value.zoomToFit();
            } else if (typeof orgChartInstance.value.resetZoom === "function") {
                orgChartInstance.value.resetZoom();
            } else if (typeof orgChartInstance.value.zoom === "function") {
                orgChartInstance.value.zoom(1);
            } else {
            }
        } catch (err) {}
    }
};

const initializeChart = () => {
    if (!chartContainer.value || !chartData.value) {
        return;
    }

    // Clear previous chart
    chartContainer.value.innerHTML = "";

    try {
        orgChartInstance.value = new OrgChart()
            .container(chartContainer.value)
            .data(chartData.value)
            .nodeHeight((d) => 85)
            .nodeWidth((d) => 220)
            .childrenMargin((d) => 50)
            .compactMarginBetween((d) => 25)
            .compactMarginPair((d) => 50)
            .neighbourMargin((a, b) => 25)
            .siblingsMargin((d) => 25)
            .layout("top")
            .compact(false)
            .buttonContent(({ node, state }) => {
                return `<div style="px;color:#716E7B;border-radius:5px;padding:4px;font-size:10px;margin:auto auto;background-color:white;border: 1px solid #E4E2E9"> <span style="font-size:9px">${
                    node.children
                        ? `<i class="fas fa-angle-up"></i>`
                        : `<i class="fas fa-angle-down"></i>`
                }</span> ${node.data._directSubordinates}  </div>`;
            })
            .linkUpdate(function (d, i, arr) {
                d3.select(this)
                    .attr("stroke", (d) =>
                        d.data._upToTheRootHighlighted ? "#152785" : "#E4E2E9"
                    )
                    .attr("stroke-width", (d) =>
                        d.data._upToTheRootHighlighted ? 5 : 1
                    );

                if (d.data._upToTheRootHighlighted) {
                    d3.select(this).raise();
                }
            })
            .nodeContent(function (d, i, arr, state) {
                const node = d.data;
                const color = "#FFFFFF";
                const statusColor =
                    node.status === "active" ? "#10B981" : "#EF4444";

                return `
                <div style="font-family: 'Inter', sans-serif;background-color:${color}; position:absolute;margin-top:-1px; margin-left:-1px;width:${d.width}px;height:${d.height}px;border-radius:10px;border: 1px solid #E4E2E9">
                   <div style="background-color:${color};position:absolute;margin-top:-25px;margin-left:${15}px;border-radius:100px;width:50px;height:50px;" ></div>
                   <div style="position:absolute;margin-top:-20px;margin-left:${20}px;border-radius:100px;width:40px;height:40px;background-color:#716E7B;display:flex;align-items:center;justify-content:center;color:white;font-size:16px;font-weight:bold;">
                       ${
                           node.isSuperUser
                               ? "👑"
                               : node.name.charAt(0).toUpperCase()
                       }
                   </div>
                   
                  <div style="color:#08011E;position:absolute;right:20px;top:17px;font-size:10px;"><i class="fas fa-ellipsis-h"></i></div>
                  <div style="position:absolute;right:20px;top:35px;width:8px;height:8px;border-radius:50%;background-color:${statusColor};"></div>

                  <div style="font-size:15px;color:#08011E;margin-left:20px;margin-top:32px"> ${
                      node.name
                  } </div>
                  <div style="color:#716E7B;margin-left:20px;margin-top:3px;font-size:10px;"> ${
                      node.title
                  } </div>

               </div>
        `;
            })
            .render();
    } catch (err) {}
};

// Watch for data changes and re-render
watch(
    chartData,
    (newData, oldData) => {
        if (newData && newData.length > 0) {
            nextTick(initializeChart);
        }
    },
    { deep: true }
);

// Also watch for changes in props.users
watch(
    () => props.users,
    (newUsers, oldUsers) => {
        if (newUsers && newUsers.length > 0) {
            nextTick(initializeChart);
        }
    },
    { deep: true }
);

onMounted(() => nextTick(initializeChart));

onUnmounted(() => {
    if (orgChartInstance.value) {
        orgChartInstance.value = null;
    }
    if (chartContainer.value) {
        chartContainer.value.innerHTML = "";
    }
});

const usersWithoutSupervisors = computed(() => {
    if (!props.users) return [];

    return props.users.filter((user) => {
        const role = getUserRole(user);
        const isSuper = user.is_super_user;

        // Only show users who should have supervisors but don't
        // Exclude Super Admin and users who already have supervisors
        return !isSuper && !user.supervisor_id;
    });
});

const handleBackToUsers = () => router.visit(route("users.index"));

const autoAssignSupervisors = async () => {
    autoAssignLoading.value = true;
    try {
        await router.post(
            route("supervisors.auto-assign"),
            {},
            {
                onSuccess: (page) => {
                    // Show success message if available

                    router.reload();
                },
                onError: (errors) => {},
            }
        );
    } catch (error) {
    } finally {
        autoAssignLoading.value = false;
    }
};
</script>

<template>
    <Head title="User Hierarchy">
        <link
            href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"
            rel="stylesheet"
        />
    </Head>
    <AuthenticatedLayout>
        <ContentHeader title="User Hierarchy" />
        <div class="max-w-7xl mx-auto p-6 space-y-6">
            <div
                class="bg-white rounded-lg shadow-sm border p-6 flex justify-between items-center"
            >
                <div class="flex items-center space-x-4">
                    <div class="p-3 bg-blue-100 rounded-lg">
                        <IconHierarchy class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">
                            User Hierarchy
                        </h2>
                        <p class="text-gray-600">
                            Supervisor-subordinate organizational structure
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <a-button
                        v-if="isSuperUser || usePermissionsV2('users.store')"
                        @click="autoAssignSupervisors"
                        :loading="autoAssignLoading"
                        type="primary"
                    >
                        <IconUserCheck /> Auto-Assign
                    </a-button>
                    <a-button @click="handleBackToUsers"
                        ><IconArrowUp /> Back</a-button
                    >
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div
                    class="stat-card bg-blue-100 text-blue-700"
                    title="Total Users"
                >
                    {{ users?.length || 0 }}
                </div>
                <div
                    class="stat-card bg-green-100 text-green-700"
                    title="Active Users"
                >
                    {{
                        users?.filter((u) => u.status === "active").length || 0
                    }}
                </div>
                <div
                    class="stat-card bg-purple-100 text-purple-700"
                    title="Top Level"
                >
                    {{ topLevelUsers.length }}
                </div>
                <div
                    class="stat-card bg-orange-100 text-orange-700"
                    title="Without Supervisor"
                >
                    {{ usersWithoutSupervisors.length }}
                </div>
            </div>

            <!-- Chart -->
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Organizational Structure
                    </h3>
                    <div class="flex gap-2" v-if="chartData">
                        <button
                            @click="exportChart"
                            class="px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors"
                        >
                            Export
                        </button>
                        <button
                            @click="resetZoom"
                            class="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors"
                        >
                            Reset Zoom
                        </button>
                    </div>
                </div>

                <div
                    v-if="chartData"
                    ref="chartContainer"
                    id="d3-org-chart"
                    class="w-full min-h-[600px] border border-gray-200 rounded"
                ></div>
                <div v-else class="text-center py-12 text-gray-600">
                    <IconUsers class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                    <p>No users found for hierarchy.</p>
                </div>
            </div>

            <!-- Users Without Supervisors Section -->
            <div
                v-if="usersWithoutSupervisors.length > 0"
                class="bg-white rounded-lg shadow-sm border p-6"
            >
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Users Without Supervisors
                    </h3>
                    <span class="text-sm text-gray-500">
                        {{ usersWithoutSupervisors.length }} users need
                        supervisor assignment
                    </span>
                </div>

                <div
                    class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
                >
                    <div
                        v-for="user in usersWithoutSupervisors"
                        :key="user.id"
                        class="bg-gray-50 rounded-lg p-4 border border-gray-200"
                    >
                        <div class="flex items-center space-x-3">
                            <div
                                class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center"
                            >
                                <span
                                    class="text-gray-600 font-semibold text-sm"
                                >
                                    {{ user.name.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-900">
                                    {{ user.name }}
                                </h4>
                                <p class="text-sm text-gray-500">
                                    {{ getUserRole(user) }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ user.email }}
                                </p>
                            </div>
                            <div class="flex items-center">
                                <span
                                    class="w-2 h-2 rounded-full"
                                    :class="
                                        user.status === 'active'
                                            ? 'bg-green-400'
                                            : 'bg-red-400'
                                    "
                                ></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-center">
                    <button
                        @click="autoAssignSupervisors"
                        :disabled="autoAssignLoading"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                        <IconUserCheck class="w-4 h-4 inline mr-2" />
                        {{
                            autoAssignLoading
                                ? "Assigning..."
                                : "Auto-Assign Supervisors"
                        }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.stat-card {
    @apply flex flex-col justify-center items-center p-4 rounded-lg shadow-sm border bg-white font-bold text-xl;
}

#d3-org-chart {
    width: 100%;
    height: auto;
    min-height: 600px;
    background-color: #fffeff;
    border-radius: 8px;
}

/* D3 Org Chart custom styles */
:deep(.org-chart-node) {
    cursor: pointer;
    transition: transform 0.2s ease;
}

:deep(.org-chart-node:hover) {
    transform: scale(1.05);
}

:deep(.org-chart-link) {
    stroke: lightgray;
    stroke-width: 1.5;
    stroke-dasharray: 4, 4;
    fill: none;
}
</style>
