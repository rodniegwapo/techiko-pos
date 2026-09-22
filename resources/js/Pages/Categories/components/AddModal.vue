<script setup>
import { computed, watch } from "vue";
import { useMediaQuery } from "@vueuse/core";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { validationMessage } from "@/Composables/useValidationMessage.js";

const { spinning } = useTable();
const page = usePage();
// `errors` is the shared bag inertiaProgressLifecyle fills on validation failure.
const { formData, openModal, isEdit, errors } = useGlobalVariables();
const { inertiaProgressLifecyle } = useHelpers();
const { getRoute } = useDomainRoutes();

const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() => (isMdUp.value ? 520 : "calc(100vw - 24px)"));
const modalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

// Start each open with no leftover errors from a previous attempt.
watch(openModal, (open) => {
    if (open) {
        errors.value = {};
    }
});

const domainOptions = computed(() => {
    const list = Array.isArray(page?.props?.domains) ? page.props.domains : [];
    return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

const handleSave = () => {
    router.post(
        getRoute("categories.store"),
        formData.value,
        inertiaProgressLifecyle
    );
};

const handleUpdate = () => {
    router.put(
        getRoute("categories.update", {
            category: formData.value.id,
        }),
        formData.value,
        inertiaProgressLifecyle
    );
};
</script>

<template>
    <a-modal
        v-model:visible="openModal"
        :title="isEdit ? 'Edit Category' : 'Add Category'"
        :width="modalWidth"
        :style="modalRootStyle"
        centered
        @cancel="openModal = false"
        :maskClosable="false"
    >
        <a-form layout="vertical">
            <a-form-item
                label="Name"
                :validate-status="validationMessage(errors, 'name') ? 'error' : ''"
                :help="validationMessage(errors, 'name')"
            >
                <a-input
                    v-model:value="formData.name"
                    placeholder="Enter category name"
                    size="large"
                />
            </a-form-item>

            <a-form-item
                label="Description"
                :validate-status="validationMessage(errors, 'description') ? 'error' : ''"
                :help="validationMessage(errors, 'description')"
            >
                <a-textarea
                    v-model:value="formData.description"
                    placeholder="Enter category description"
                    :rows="4"
                    size="large"
                />
            </a-form-item>

            <!-- Domain field for global view -->
            <a-form-item
                v-if="page.props.isGlobalView"
                label="Domain"
                :validate-status="validationMessage(errors, 'domain') ? 'error' : ''"
                :help="validationMessage(errors, 'domain')"
            >
                <a-select
                    v-model:value="formData.domain"
                    :options="domainOptions"
                    placeholder="Select domain"
                    size="large"
                />
            </a-form-item>
        </a-form>

        <template #footer>
            <a-button @click="openModal = false">Cancel</a-button>

            <primary-button
                v-if="isEdit"
                :loading="spinning"
                @click="handleUpdate"
                >Update
            </primary-button>
            <primary-button v-else :loading="spinning" @click="handleSave"
                >Submit
            </primary-button>
        </template>
    </a-modal>
</template>
