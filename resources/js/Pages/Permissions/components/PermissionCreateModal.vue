<script setup>
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { IconShield } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    permissionModules: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["close", "saved"]);

const form = useForm({
    name: "",
    route_name: "",
    description: "",
    module: "",
    module_display_name: "",
    action: "",
});

// Only `value` per option — combobox mode does not support `label` + optionLabelProp.
const moduleOptions = computed(() =>
    (props.permissionModules || []).map((m) => ({
        value: m.name,
    })),
);

const isNewModule = computed(() => {
    const m = form.module;
    if (!m || typeof m !== "string") return false;
    return !(props.permissionModules || []).some((x) => x.name === m);
});

function normalizeModuleSlug(s) {
    if (!s || typeof s !== "string") return "";
    return s
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "");
}

function onModuleBlur() {
    const n = normalizeModuleSlug(form.module);
    if (n) form.module = n;
}

const routeName = computed(() => {
    if (form.module && form.action) {
        return `${form.module}.${form.action}`;
    }
    return "";
});

const updateRouteName = () => {
    if (form.module && form.action) {
        form.route_name = `${form.module}.${form.action}`;
    }
};

const handleSubmit = () => {
    form.post(route("permissions.store"), {
        onSuccess: () => {
            notification.success({
                message: "Permission Created",
                description: `Permission "${form.name}" has been created successfully`,
            });
            handleClose();
            emit("saved");
        },
        onError: () => {
            notification.error({
                message: "Create Failed",
                description:
                    "Failed to create permission. Please check the form for errors.",
            });
        },
    });
};

const handleClose = () => {
    form.reset();
    form.clearErrors();
    emit("close");
};

const handleCancel = () => {
    handleClose();
};

const actions = [
    { value: "index", label: "View (index)" },
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
            <a-form-item
                label="Module"
                name="module"
                :validate-status="form.errors.module ? 'error' : ''"
                :help="form.errors.module"
                required
            >
                <a-auto-complete
                    v-model:value="form.module"
                    :options="moduleOptions"
                    placeholder="Search or type a new module slug"
                    class="w-full"
                    @blur="onModuleBlur"
                >
                    <a-input />
                </a-auto-complete>
                <div class="text-sm text-gray-500 mt-1">
                    Pick an existing module or type a slug (list shows slugs; a
                    new module row is created on save if missing).
                </div>
            </a-form-item>

            <a-form-item
                v-if="isNewModule"
                label="New module display name"
                name="module_display_name"
                :validate-status="
                    form.errors.module_display_name ? 'error' : ''
                "
                :help="form.errors.module_display_name"
            >
                <a-input
                    v-model:value="form.module_display_name"
                    placeholder="e.g. VAT Report"
                />
            </a-form-item>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                        @change="updateRouteName"
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

            <a-form-item
                label="Display Name"
                name="name"
                :validate-status="form.errors.name ? 'error' : ''"
                :help="form.errors.name"
                required
            >
                <a-input
                    v-model:value="form.name"
                    placeholder="e.g., Create Product, View Users, etc."
                >
                    <template #prefix>
                        <IconShield class="text-blue-500" />
                    </template>
                </a-input>
                <div class="text-sm text-gray-500 mt-1">
                    Human-readable name for this permission
                </div>
            </a-form-item>

            <a-form-item
                label="Route Name"
                name="route_name"
                :validate-status="form.errors.route_name ? 'error' : ''"
                :help="form.errors.route_name"
                required
            >
                <a-input
                    v-model:value="form.route_name"
                    placeholder="Auto-generated from module and action"
                >
                    <template #prefix>
                        <IconShield class="text-green-500" />
                    </template>
                </a-input>
                <div class="text-sm text-gray-500 mt-1">
                    Technical route name: {{ routeName || "module.action" }}
                </div>
            </a-form-item>

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
                <a-button @click="handleCancel"> Cancel </a-button>
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
