<template>
    <a-modal
        :visible="visible"
        :title="isEdit ? 'Edit User' : 'Add New User'"
        :width="modalWidth"
        :style="modalRootStyle"
        :body-style="modalBodyStyle"
        wrap-class-name="modal-footer-full-mobile"
        centered
        @cancel="handleCancel"
    >
        <a-form
            ref="formRef"
            class="modal-form-stack"
            :model="form"
            :rules="rules"
            layout="vertical"
            :colon="false"
            @finish="handleSave"
        >
            <div class="flex flex-col md:grid md:grid-cols-2 md:gap-4">
                <a-form-item
                    label="Full Name"
                    name="name"
                    class="md:col-span-2"
                >
                    <a-input
                        v-model:value="form.name"
                        class="w-full"
                        placeholder="Enter user's full name"
                    />
                </a-form-item>

                <a-form-item label="Email Address" name="email">
                    <a-input
                        v-model:value="form.email"
                        class="w-full"
                        placeholder="user@example.com"
                        type="email"
                    />
                </a-form-item>

                <a-form-item
                    v-if="isOwnRoleLocked"
                    label="Role"
                >
                    <a-input :value="ownRoleName" disabled />
                </a-form-item>
                <a-form-item v-else label="Role" name="role_id">
                    <a-select
                        v-model:value="form.role_id"
                        class="w-full"
                        placeholder="Select user role"
                        :options="availableRoles"
                        @change="onRoleChange"
                    />
                </a-form-item>
            </div>

            <!-- Domain field for global view or super users -->
            <a-form-item
                v-if="page.props.isGlobalView || currentUser.is_super_user"
                label="Domain"
                name="domain"
            >
                <a-select
                    v-model:value="form.domain"
                    class="w-full"
                    placeholder="Select domain"
                    :options="domainOptions"
                    @change="onDomainChange"
                />
            </a-form-item>

            <!-- Dynamic Supervisor Assignment -->
            <div v-if="selectedRole && assignLabel" class="mt-4">
                <a-form-item :label="assignLabel" name="supervisor_id">
                    <a-select
                        v-model:value="form.supervisor_id"
                        class="w-full"
                        :placeholder="`Select ${assignLabel.toLowerCase()}`"
                        :options="availableSupervisors"
                        :loading="loadingSupervisors"
                        allow-clear
                        show-search
                        :filter-option="filterOption"
                    >
                        <template #option="{ label, value }">
                            <div class="flex items-center justify-between">
                                <span>{{ label }}</span>
                                <a-tag
                                    size="small"
                                    :color="getSupervisorRoleColor(value)"
                                >
                                    {{ getSupervisorRoleName(value) }}
                                </a-tag>
                            </div>
                        </template>
                    </a-select>
                </a-form-item>
            </div>

            <div class="flex flex-col md:grid md:grid-cols-2 md:gap-4">
                <a-form-item label="Password" name="password">
                    <a-input-password
                        v-model:value="form.password"
                        class="w-full"
                        :placeholder="
                            isEdit
                                ? 'Leave blank to keep current password'
                                : 'Enter password'
                        "
                        autocomplete="new-password"
                    />
                </a-form-item>

                <a-form-item
                    label="Confirm Password"
                    name="password_confirmation"
                >
                    <a-input-password
                        v-model:value="form.password_confirmation"
                        class="w-full"
                        placeholder="Confirm password"
                        autocomplete="new-password"
                    />
                </a-form-item>
            </div>

            <!-- Role Information -->
            <div
                v-if="selectedRole"
                class="bg-blue-50 rounded-lg p-4 border border-blue-200"
            >
                <h4
                    class="text-sm font-medium text-blue-900 mb-2 flex items-center"
                >
                    <safety-certificate-outlined class="mr-2" />
                    Role Permissions
                </h4>
                <div class="text-sm text-blue-700">
                    <strong>{{ selectedRole.name }}</strong> -
                    {{ getRoleDescription(selectedRole.name) }}
                </div>
            </div>
        </a-form>

        <!-- User Preview -->
        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
            <h4 class="text-sm font-medium text-gray-900 mb-3">Preview</h4>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                <a-avatar
                    :size="48"
                    :style="{ backgroundColor: getAvatarColor(form.name) }"
                >
                    {{ getInitials(form.name) }}
                </a-avatar>
                <div>
                    <div class="font-medium">
                        {{ form.name || "User Name" }}
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ form.email || "email@example.com" }}
                    </div>
                    <div v-if="selectedRole" class="text-xs font-medium mt-1">
                        <a-tag :color="getRoleColor(selectedRole.name)">
                            {{ selectedRole.name }}
                        </a-tag>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div
                class="modal-footer-actions flex w-full flex-col gap-2 md:flex-row md:justify-end"
            >
                <a-button class="w-full md:w-auto" @click="handleCancel">
                    Cancel
                </a-button>
                <a-button
                    type="primary"
                    class="w-full md:w-auto"
                    :loading="saving"
                    @click="handleSave"
                >
                    {{ isEdit ? "Update User" : "Add User" }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { ref, watch, reactive, computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { notification } from "ant-design-vue";
import { SafetyCertificateOutlined } from "@ant-design/icons-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute, isInDomainContext } = useDomainRoutes();

/** Global /users has no web store/update — CRUD goes through /api/users. */
const userStoreUrl = () => {
    if (isInDomainContext.value) {
        return getRoute("users.store");
    }
    return "/api/users";
};

const userUpdateUrl = (userId) => {
    if (isInDomainContext.value) {
        return getRoute("users.update", { user: userId });
    }
    return `/api/users/${userId}`;
};

const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() =>
    isMdUp.value ? 600 : "calc(100vw - 24px)",
);
const modalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);
const modalBodyStyle = computed(() =>
    isMdUp.value ? {} : { maxHeight: "calc(100vh - 120px)", overflowY: "auto" },
);

// Domain options
const domainOptions = computed(() => {
    const list = Array.isArray(page?.props?.domains) ? page.props.domains : [];
    return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

// Props
const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    user: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
    roles: {
        type: Array,
        default: () => [],
    },
});

// Emits
const emit = defineEmits(["close", "saved"]);

// Form reference
const formRef = ref();
const saving = ref(false);

// Form data
const form = reactive({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    role_id: null,
    supervisor_id: null,
    domain: page.props.isGlobalView
        ? null
        : page.props.currentDomain?.name_slug || null,
});

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

const isSuperUser = computed(
    () => currentUser.value?.is_super_user || false
);

const editingUserData = computed(() => {
    if (!props.user) return null;
    return props.user.data || props.user;
});

const isEditingSelf = computed(() => {
    if (!props.isEdit || !editingUserData.value || !currentUser.value) {
        return false;
    }
    return editingUserData.value.id === currentUser.value.id;
});

const isOwnRoleLocked = computed(
    () => isEditingSelf.value && !isSuperUser.value
);

const ownRoleName = computed(() => {
    return (
        editingUserData.value?.roles?.[0]?.name ||
        selectedRole.value?.name ||
        "—"
    );
});

// Available roles (filter out super admin for regular admins)
const availableRoles = computed(() => {
    let roles = props.roles.map((role) => ({
        label: role.name,
        value: role.id,
    }));

    // If current user is not super admin, exclude super admin role
    if (
        !currentUser.value.roles?.some(
            (role) => role.name.toLowerCase() === "super admin"
        )
    ) {
        roles = roles.filter(
            (role) => role.label.toLowerCase() !== "super admin"
        );
    }

    return roles;
});

// Selected role info
const selectedRole = computed(() => {
    return props.roles.find((role) => role.id === form.role_id);
});

// Dynamic assignment label based on selected role
const assignLabel = computed(() => {
    if (!selectedRole.value) return null;

    const roleName = selectedRole.value.name.toLowerCase();
    const assignmentMap = {
        cashier: "Assign Supervisor",
        supervisor: "Assign Manager",
        manager: "Assign Admin",
        admin: "Assign Super Admin",
    };

    return assignmentMap[roleName] || null;
});

// Supervisor assignment state
const availableSupervisors = ref([]);
const loadingSupervisors = ref(false);
const supervisorUsers = ref([]);

// Form validation rules
const rules = computed(() => ({
    name: [{ required: true, message: "Please enter user name" }],
    email: [
        { required: true, message: "Please enter email address" },
        { type: "email", message: "Please enter a valid email address" },
    ],
    password: props.isEdit
        ? [{ min: 8, message: "Password must be at least 8 characters" }]
        : [
              { required: true, message: "Please enter password" },
              { min: 8, message: "Password must be at least 8 characters" },
          ],
    password_confirmation: [
        {
            validator: (rule, value) => {
                if (form.password && value !== form.password) {
                    return Promise.reject("Passwords do not match");
                }
                return Promise.resolve();
            },
        },
    ],
    role_id: isOwnRoleLocked.value
        ? []
        : [{ required: true, message: "Please select a role" }],
}));

// Watch for user changes
watch(
    () => props.user,
    async (newUser) => {
        console.log("User prop changed:", newUser, "isEdit:", props.isEdit);

        if (newUser && props.isEdit) {
            // Handle data wrapping from resources
            const userData = newUser.data || newUser;

            Object.assign(form, {
                name: userData.name || "",
                email: userData.email || "",
                password: "",
                password_confirmation: "",
                role_id: userData.roles?.[0]?.id || null,
                supervisor_id: userData.supervisor_id || null,
                domain: userData.domain || null,
            });

            console.log("Form assigned for edit:", form);
            console.log("User supervisor_id:", userData.supervisor_id);
            console.log("User supervisor:", userData.supervisor);
            console.log("User domain:", userData.domain);
            console.log("assignLabel:", assignLabel.value);

            // Fetch available supervisors for the current role when editing
            if (form.role_id && assignLabel.value) {
                console.log("Fetching supervisors for edit mode");
                await fetchAvailableSupervisors();
            }
        } else {
            // Reset form for new user
            Object.assign(form, {
                name: "",
                email: "",
                password: "",
                password_confirmation: "",
                role_id: null,
                supervisor_id: null,
            });
            availableSupervisors.value = [];
        }
    },
    { immediate: true }
);

// Watch for modal visibility to load supervisors when editing
watch(
    () => props.visible,
    async (isVisible) => {
        console.log(
            "Modal visibility changed:",
            isVisible,
            "isEdit:",
            props.isEdit,
            "user:",
            props.user
        );

        if (isVisible && props.isEdit && props.user) {
            const userData = props.user.data || props.user;
            console.log("Modal opened for edit, user data:", userData);

            if (form.role_id && assignLabel.value) {
                console.log("Fetching supervisors for modal edit");
                await fetchAvailableSupervisors();
            }
        }
    }
);

// Watch for role changes to fetch supervisors
watch(
    () => form.role_id,
    (newRoleId) => {
        if (newRoleId && assignLabel.value) {
            fetchAvailableSupervisors();
        } else {
            availableSupervisors.value = [];
            form.supervisor_id = null;
        }
    }
);

// Watch for domain changes to refresh supervisor options
watch(
    () => form.domain,
    (newDomain, oldDomain) => {
        console.log("Domain changed from", oldDomain, "to", newDomain);
        if (newDomain && form.role_id && assignLabel.value) {
            console.log("Domain changed, refreshing supervisors for domain:", newDomain);
            fetchAvailableSupervisors();
        }
    }
);

// Methods
const onRoleChange = () => {
    // Reset supervisor when role changes
    form.supervisor_id = null;
};

const onDomainChange = () => {
    console.log("Domain change handler triggered, new domain:", form.domain);
    // Reset supervisor when domain changes
    form.supervisor_id = null;
    // Clear available supervisors to force refresh
    availableSupervisors.value = [];
};

const fetchAvailableSupervisors = async () => {
    if (!selectedRole.value) {
        console.log("No selected role, skipping supervisor fetch");
        return;
    }

    console.log("Fetching supervisors for role:", selectedRole.value.name);
    loadingSupervisors.value = true;

    try {
        // Use the domain-aware route to get available supervisors
        const response = await axios.get(getRoute('supervisors.available'), {
            params: { role: selectedRole.value.name, cascading: true },
        });

        console.log("Supervisors API response:", response.data);

        if (response.data?.supervisors) {
            supervisorUsers.value = response.data.supervisors;
            console.log("supervisorUsers:", response.data.supervisors);
            availableSupervisors.value = response.data.supervisors.map(
                (user) => ({
                    label: user.name,
                    value: user.id,
                    role: user.roles?.[0]?.name || "No Role",
                })
            );
            console.log(
                "availableSupervisors mapped:",
                availableSupervisors.value
            );
        } else {
            console.log("No supervisors found in response");
            availableSupervisors.value = [];
        }
    } catch (error) {
        console.error("Error fetching supervisors:", error);
        console.error("Error details:", error.response?.data);
        availableSupervisors.value = [];
    } finally {
        loadingSupervisors.value = false;
    }
};

const filterOption = (input, option) => {
    return option.label.toLowerCase().indexOf(input.toLowerCase()) >= 0;
};

const getSupervisorRoleColor = (userId) => {
    const user = supervisorUsers.value.find((u) => u.id === userId);
    if (!user) return "default";

    const roleName = user.roles?.[0]?.name?.toLowerCase() || "";
    const colors = {
        "super admin": "red",
        admin: "blue",
        manager: "green",
        supervisor: "purple",
        cashier: "orange",
    };
    return colors[roleName] || "default";
};

const getSupervisorRoleName = (userId) => {
    const user = supervisorUsers.value.find((u) => u.id === userId);
    return user?.roles?.[0]?.name || "No Role";
};

const handleSave = async () => {
    try {
        await formRef.value.validate();
        saving.value = true;

        const userData = {
            name: form.name,
            email: form.email,
            supervisor_id: form.supervisor_id,
            domain: form.domain || undefined,
        };

        // Own role is locked for non-super users — omit role_id so it cannot be changed
        if (!isOwnRoleLocked.value) {
            userData.role_id = form.role_id;
        }

        // Only include password if it's provided
        if (form.password) {
            userData.password = form.password;
            userData.password_confirmation = form.password_confirmation;
        }

        console.log("Saving user data:", userData);

        if (props.isEdit && editingUserData.value) {
            await axios.put(userUpdateUrl(editingUserData.value.id), userData);
            notification.success({
                message: "User Updated",
                description: `${userData.name} has been updated successfully`,
            });
        } else {
            await axios.post(userStoreUrl(), userData);
            notification.success({
                message: "User Created",
                description: `${userData.name} has been created successfully`,
            });
        }

        emit("saved");
    } catch (error) {
        console.error("Save user error:", error);

        let errorMessage = "Failed to save user";
        if (error.response?.data?.errors) {
            const errors = error.response.data.errors;
            const firstError = Object.values(errors)[0];
            errorMessage = Array.isArray(firstError)
                ? firstError[0]
                : firstError;
        } else if (error.response?.data?.message) {
            errorMessage = error.response.data.message;
        }

        notification.error({
            message: "Save Failed",
            description: errorMessage,
        });
    } finally {
        saving.value = false;
    }
};

const handleCancel = () => {
    emit("close");
};

const getInitials = (name) => {
    if (!name) return "?";
    return name
        .split(" ")
        .map((word) => word.charAt(0))
        .join("")
        .toUpperCase()
        .slice(0, 2);
};

const getAvatarColor = (name) => {
    const colors = [
        "#f56565",
        "#ed8936",
        "#ecc94b",
        "#48bb78",
        "#38b2ac",
        "#4299e1",
        "#667eea",
        "#9f7aea",
        "#ed64a6",
        "#a0aec0",
    ];
    if (!name) return colors[0];
    const index = name.charCodeAt(0) % colors.length;
    return colors[index];
};

const getRoleColor = (roleName) => {
    const roleColors = {
        "Super Admin": "red",
        Admin: "orange",
        Manager: "blue",
        Cashier: "green",
    };
    return roleColors[roleName] || "default";
};

const getRoleDescription = (roleName) => {
    const descriptions = {
        "Super Admin":
            "Full system access including user management and system settings",
        Admin: "Administrative access with user management capabilities",
        Manager: "Operational management with reporting and staff oversight",
        Cashier: "Basic sales operations and customer service",
    };
    return descriptions[roleName] || "Standard user permissions";
};
</script>
