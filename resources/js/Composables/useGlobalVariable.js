import { ref } from "vue";

let formData = ref({});
const isLoading = ref(false);
const errorMessage = ref("");
const openModal = ref(false);
const isEdit = ref(false);
const spinning = ref(false);
const errors = ref({});
const formFilters = ref({});
const openKeys = ref([]); // for menus
const selectedKeys = ref([]);// for menus

export function useGlobalVariables() {
    return {
        formData,
        formFilters,
        isLoading,
        errorMessage,
        openModal,
        isEdit,
        spinning,
        errors,
        selectedKeys,
        openKeys
    };
}
