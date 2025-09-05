// composables/useFormData.js
import { ref } from "vue";

const formData = ref({});
const isLoading = ref(false);
const errorMessage = ref("");
const openModal = ref(false);
const isEdit = ref(false);
const spinning = ref(false);

export function useGlobalVariables() {
    return {
        formData,
        isLoading,
        errorMessage,
        openModal,
        isEdit,
        spinning,
    };
}
