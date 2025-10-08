<script setup>
import { ref, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { IconShield } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'saved']);

// Form handling
const form = useForm({
    name: "",
    description: "",
    module: "",
    action: "",
});

// Available modules and actions
const modules = [
    { value: "users", label: "Users" },
    { value: "roles", label: "Roles" },
    { value: "permissions", label: "Permissions" },
    { value: "products", label: "Products" },
    { value: "categories", label: "Categories" },
    { value: "inventory", label: "Inventory" },
    { value: "sales", label: "Sales" },
    { value: "customers", label: "Customers" },
    { value: "loyalty", label: "Loyalty" },
    { value: "discounts", label: "Discounts" },
    { value: "reports", label: "Reports" },
    { value: "settings", label: "Settings" },
];

const actions = [
    { value: "view", label: "View" },
    { value: "create", label: "Create" },
    { value: "edit", label: "Edit" },
    { value: "delete", label: "Delete" },
    { value: "manage", label: "Manage" },
    { value: "export", label: "Export" },
    { value: "import", label: "Import" },
    { value: "approve", label: "Approve" },
    { value: "reject", label: "Reject" },
];

// Computed property for auto-generated permission name
const permissionName = computed(() => {
    if (form.module && form.action) {
        return `${form.module}.${form.action}`;
    }
    return "";
});

// Watch for changes to update the name field
const updatePermissionName = () => {
    if (form.module && form.action) {
        form.name = `${form.module}.${form.action}`;
    }
};

// Methods
const handleSubmit = () => {
    form.post(route("permissions.store"), {
        onSuccess: () => {
            notification.success({
                message: "Permission Created",
                description: `Permission "${form.name}" has been created successfully`,
            });
            handleClose();
            emit('saved');
        },
        onError: (errors) => {
            console.error("Form errors:", errors);
            notification.error({
                message: "Create Failed",
                description: "Failed to create permission. Please check the form for errors.",
            });
        },
    });
};

const handleClose = () => {
    form.reset();
    form.clearErrors();
    emit('close');
};

const handleCancel = () => {
    handleClose();
};
</script>

<template>
    <a-modal
        :visible="visible"
        title="Create New Permission"
        :confirm-loading="form.processing"
        @ok="handleSubmit"
        @cancel="handleCancel"
        width="600px"
        :destroy-on-close="true"
    >
        <a-form
            :model="form"
            @finish="handleSubmit"
            layout="vertical"
            class="space-y-4"
        >
            <!-- Module and Action Selection -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <a-form-item
                    label="Module"
                    name="module"
                    :validate-status="form.errors.module ? 'error' : ''"
                    :help="form.errors.module"
                    required
                >
                    <a-select
                        v-model:value="form.module"
                        placeholder="Select module"
                        @change="updatePermissionName"
                    >
                        <a-select-option
                            v-for="module in modules"
                            :key="module.value"
                            :value="module.value"
                        >
                            {{ module.label }}
                        </a-select-option>
                    </a-select>
                </a-form-item>

                <a-form-item
                    label="Action"
                    name="action"
                    :validate-status="form.errors.action ? 'error' : ''"
                    :help="form.errors.action"
                    required
                >
                    <a-select
                        v-model:value="form.action"
                        placeholder="Select action"
                        @change="updatePermissionName"
                    >
                        <a-select-option
                            v-for="action in actions"
                            :key="action.value"
                            :value="action.value"
                        >
                            {{ action.label }}
                        </a-select-option>
                    </a-select>
                </a-form-item>
            </div>

            <!-- Auto-generated Permission Name -->
            <a-form-item
                label="Permission Name"
                name="name"
                :validate-status="form.errors.name ? 'error' : ''"
                :help="form.errors.name"
                required
            >
                <a-input
                    v-model:value="form.name"
                    placeholder="Auto-generated from module and action"
                    readonly
                >
                    <template #prefix>
                        <IconShield class="text-blue-500" />
                    </template>
                </a-input>
                <div class="text-sm text-gray-500 mt-1">
                    This will be automatically generated as: {{ permissionName || 'module.action' }}
                </div>
            </a-form-item>

            <!-- Description -->
            <a-form-item
                label="Description"
                name="description"
                :validate-status="form.errors.description ? 'error' : ''"
                :help="form.errors.description"
            >
                <a-textarea
                    v-model:value="form.description"
                    placeholder="Optional description for this permission"
                    :rows="3"
                />
            </a-form-item>
        </a-form>

        <template #footer>
            <div class="flex justify-end gap-2">
                <a-button @click="handleCancel">
                    Cancel
                </a-button>
                <a-button
                    type="primary"
                    @click="handleSubmit"
                    :loading="form.processing"
                >
                    Create Permission
                </a-button>
            </div>
        </template>
    </a-modal>
</template>
