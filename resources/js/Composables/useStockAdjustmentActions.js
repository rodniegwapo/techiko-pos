import { router } from "@inertiajs/vue3";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * Submit / approve / reject a stock adjustment. The controller answers a failed action with a
 * redirect carrying flash.error, which Inertia still reports as a success, so check for it here.
 */
export function useStockAdjustmentActions() {
  const { showNotification } = useHelpers();

  const post = (routeName, adjustment, successMessage, failureMessage, onDone) =>
    router.post(
      route(routeName, adjustment.id),
      {},
      {
        preserveScroll: true,
        onSuccess: (page) => {
          const error = page.props?.flash?.error;
          if (error) {
            showNotification("error", "Error", error);
            return;
          }
          showNotification("success", "Success", successMessage);
          onDone?.();
        },
        onError: () => {
          showNotification("error", "Error", failureMessage);
        },
      }
    );

  const submitAdjustment = (adjustment, onDone) =>
    post(
      "inventory.adjustments.submit",
      adjustment,
      "Adjustment submitted for approval",
      "Failed to submit adjustment",
      onDone
    );

  const approveAdjustment = (adjustment, onDone) =>
    post(
      "inventory.adjustments.approve",
      adjustment,
      "Adjustment approved and processed",
      "Failed to approve adjustment",
      onDone
    );

  const rejectAdjustment = (adjustment, onDone) =>
    post(
      "inventory.adjustments.reject",
      adjustment,
      "Adjustment rejected",
      "Failed to reject adjustment",
      onDone
    );

  return { submitAdjustment, approveAdjustment, rejectAdjustment };
}
