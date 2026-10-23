import { ref } from "vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

export function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return err?.response?.data?.message || err?.message || null;
}

/**
 * Today's drawer actions: set opening cash, count the cash, end and reopen the shift. Each posts
 * for one store and business date, then reloads the page.
 */
export function useCashDrawerActions(locationId, businessDate) {
    const { getRoute } = useDomainRoutes();
    const busy = ref(null);

    async function post(name, routeName, payload, done, failed) {
        busy.value = name;
        try {
            await axios.post(getRoute(routeName), {
                location_id: locationId.value,
                business_date: businessDate.value,
                ...payload,
            });
            notification.success({ message: done });
            router.reload({ preserveScroll: true });
            return true;
        } catch (e) {
            notification.error({ message: firstValidationMessage(e) || failed });
            return false;
        } finally {
            busy.value = null;
        }
    }

    return {
        busy,
        saveOpening: (amount, reason) =>
            post("opening", "wallet-cash-ledger.opening-cash.store", { opening_cash: Number(amount || 0), reason: reason || null },
                "Opening cash saved.", "Could not save opening cash."),
        saveCount: (amount, notes) =>
            post("count", "wallet-cash-ledger.counted-cash.store", { counted_cash: Number(amount || 0), notes: notes || null },
                "Count saved.", "Could not save the count."),
        /** Count, cash expenses, a manager's PIN for a cashier, and closing, all in one go. */
        endShift: (payload) =>
            post("end", "wallet-cash-ledger.close-shift", payload,
                "Shift ended.", "Could not end the shift."),
        reopenShift: () =>
            post("reopen", "wallet-cash-ledger.reopen-shift", {}, "Shift reopened.", "Could not reopen the shift."),
    };
}
