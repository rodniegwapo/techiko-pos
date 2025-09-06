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

const { formData, spinning, errors, openModal,isEdit } = useGlobalVariables();
const { emitClose } = useEmits();

export function useHelpers() {
    const showModal = () => {
        formData.value = {};
        openModal.value = true;
        isEdit.value = false;
    };

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
        return new Promise((resolve, reject) => {
            Modal.confirm({
                title: "Confirm Delete",
                icon: createVNode(ExclamationCircleOutlined),
                content: message,
                okText: "Delete",
                cancelText: "Cancel",
                onOk: () => {
                    return new Promise((innerResolve, innerReject) => {
                        router.delete(route(routeName, params), {
                            onSuccess: (page) => {
                                resolve(page);
                                innerResolve();
                            },
                            onError: (errors) => {
                                reject(errors);
                                innerReject();
                            },
                        });
                    });
                },
                onCancel: () => reject(new Error("User canceled")),
            });
        });
    };

    return {
        confirmDelete,
        inertiaProgressLifecyle,
        showModal
    };
}
