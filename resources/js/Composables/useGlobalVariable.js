import { ref } from "vue";

let formData = ref({});
const isLoading = ref(false);
const errorMessage = ref("");
const openModal = ref(false);
const isEdit = ref(false);
const spinning = ref(false);
let errors = ref({});
let formFilters = ref({});
const openKeys = ref([]); // for menus
const selectedKeys = ref([]);// for menus
const orders = ref([])

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
        openKeys,
        orders
    };
}
