// composables/useConfirmDelete.js
import { createVNode } from "vue";
import { Modal } from "ant-design-vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import { router } from "@inertiajs/vue3";

/**
 * Composable for showing delete confirmation modals
 *
 * Example usage:
 * const { confirmDelete } = useConfirmDelete();
 * confirmDelete("categories.destroy", { id: record.id }, "Do you want to delete this item?");
 */
export function useHelpers() {
  const confirmDelete = (routeName, params = {}, message = "Do you want to delete this item?") => {
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
  };
}
