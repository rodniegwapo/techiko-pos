<script setup>
import { ref, computed } from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { useEmits } from "@/Composables/useEmits";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const { spinning } = useTable();
const page = usePage();
const { formData, openModal, isEdit } = useGlobalVariables();
const { inertiaProgressLifecyle } = useHelpers();
const { getRoute } = useDomainRoutes();

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
});

const { emitClose, emitEvent } = useEmits();

const domainOptions = computed(() => {
    const list = Array.isArray(page?.props?.domains) ? page.props.domains : [];
    return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

const errors = ref({});
const handleSave = () => {
    console.log("current route", getRoute("categories.store"));
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
        @cancel="openModal = false"
        :maskClosable="false"
    >
        <a-form layout="vertical">
            <a-form-item 
                label="Name" 
                :validate-status="errors.name ? 'error' : ''"
                :help="errors.name || ''"
            >
                <a-input 
                    v-model:value="formData.name" 
                    placeholder="Enter category name"
                    size="large"
                />
            </a-form-item>
            
            <a-form-item 
                label="Description" 
                :validate-status="errors.description ? 'error' : ''"
                :help="errors.description || ''"
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
                :validate-status="errors.domain ? 'error' : ''"
                :help="errors.domain || ''"
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
