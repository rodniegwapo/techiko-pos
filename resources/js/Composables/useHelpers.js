// composables/useConfirmDelete.js
import { createVNode } from "vue";
import { Modal } from "ant-design-vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import { router } from "@inertiajs/vue3";
import { useGlobalVariables } from "./useGlobalVariable";
import { useEmits } from "./useEmits";
/**
 * Composable for showing delete confirmation modals
 *
 * Example usage:
 * const { confirmDelete } = useConfirmDelete();
 * confirmDelete("categories.destroy", { id: record.id }, "Do you want to delete this item?");
 */

const { formData, spinning, errors,openModal } = useGlobalVariables();
const { emitClose } = useEmits();

export function useHelpers() {
    const inertiaProgressLifecyle = {
        onSuccess: () => {
            openModal.value = false;
            formData.value = {};
        },
        onStart: () => {
            spinning.value = true;
        },
        onError: (error) => {
            errors.value = error;
        },
        onFinish: () => {
            spinning.value = false;
        },
    };

    const confirmDelete = (
        routeName,
        params = {},
        message = "Do you want to delete this item?"
    ) => {
        Modal.confirm({
            title: "Confirm Delete",
            icon: createVNode(ExclamationCircleOutlined),
            content: message,
            onOk() {
                router.delete(route(routeName, params));
            },
            onCancel() {
                // optional: handle cancel if needed
            },
        });
    };

    return {
        confirmDelete,
        inertiaProgressLifecyle,
    };
}
