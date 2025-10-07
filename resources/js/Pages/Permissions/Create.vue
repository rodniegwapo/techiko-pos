<script setup>
import { ref, computed } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { IconArrowLeft, IconShield } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";

const props = defineProps({
    modules: Array,
});

// Form handling
const form = useForm({
    name: "",
    module: "",
    action: "",
    description: "",
});

// Computed
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
            // Success handled by Inertia
        },
        onError: (errors) => {
            console.error("Form errors:", errors);
        },
    });
};

const handleCancel = () => {
    router.visit(route("permissions.index"));
};

// Common actions for suggestions
const commonActions = [
    "index", "show", "create", "store", "edit", "update", "destroy", "view"
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Create Permission" />
        <ContentHeader class="mb-8" title="Create Permission" />

        <ContentLayout title="Create Permission">
            <div class="max-w-2xl mx-auto">
                <a-form
                    :model="form"
                    @finish="handleSubmit"
                    layout="vertical"
                    class="space-y-6"
                >
                    <!-- Module -->
                    <a-form-item
                        label="Module"
                        name="module"
                        :validate-status="form.errors.module ? 'error' : ''"
                        :help="form.errors.module"
                        required
                    >
                        <a-select
                            v-model:value="form.module"
                            placeholder="Select or type a module name"
                            show-search
                            allow-clear
                            @change="updatePermissionName"
                        >
                            <a-select-option
                                v-for="module in modules"
                                :key="module"
                                :value="module"
                            >
                                {{ module }}
                            </a-select-option>
                        </a-select>
                    </a-form-item>

                    <!-- Action -->
                    <a-form-item
                        label="Action"
                        name="action"
                        :validate-status="form.errors.action ? 'error' : ''"
                        :help="form.errors.action"
                        required
                    >
                        <a-select
                            v-model:value="form.action"
                            placeholder="Select or type an action"
                            show-search
                            allow-clear
                            @change="updatePermissionName"
                        >
                            <a-select-option
                                v-for="action in commonActions"
                                :key="action"
                                :value="action"
                            >
                                {{ action }}
                            </a-select-option>
                        </a-select>
                    </a-form-item>

                    <!-- Permission Name (Auto-generated) -->
                    <a-form-item
                        label="Permission Name"
                        name="name"
                        :validate-status="form.errors.name ? 'error' : ''"
                        :help="form.errors.name"
                    >
                        <a-input
                            v-model:value="form.name"
                            placeholder="Auto-generated from module and action"
                            readonly
                            class="bg-gray-50"
                        >
                            <template #prefix>
                                <IconShield class="text-blue-500" />
                            </template>
                        </a-input>
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

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-3 pt-6 border-t">
                        <a-button @click="handleCancel" size="large">
                            <template #icon>
                                <IconArrowLeft />
                            </template>
                            Cancel
                        </a-button>
                        <a-button
                            type="primary"
                            html-type="submit"
                            :loading="form.processing"
                            size="large"
                        >
                            Create Permission
                        </a-button>
                    </div>
                </a-form>
            </div>
        </ContentLayout>
    </AuthenticatedLayout>
</template>

