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
 * Today's drawer actions: set opening cash, count the cash, end and reopen the store's day, and the
 * user's own shift (start, end, reopen). Each posts for one store and business date, then reloads
 * the page.
 */
export function useCashDrawerActions(locationId, businessDate) {
    const { getRoute } = useDomainRoutes();
    const busy = ref(null);

    async function post(name, routeName, payload, done, failed, params = {}) {
        busy.value = name;
        try {
            await axios.post(getRoute(routeName, params), {
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
        /** Your own tray during your shift, against that shift's Expected. */
        spotCheckShift: (shiftId, amount, notes) =>
            post("spot", "register-shifts.spot-check", { counted_cash: Number(amount || 0), notes: notes || null },
                "Spot check saved.", "Could not save the spot check.", { registerShift: shiftId }),
        /** The store's day: count, cash expenses and closing, all in one go (managers, after every cashier's shift). */
        endShift: (payload) =>
            post("end", "wallet-cash-ledger.close-shift", payload,
                "Day ended.", "Could not end the day."),
        /** Reopening needs a reason; who and when are kept in the reopen log. */
        reopenShift: (reason) =>
            post("reopen", "wallet-cash-ledger.reopen-shift", { reason }, "Day reopened.", "Could not reopen the day."),

        startMyShift: (openingCash, notes) =>
            post("my-start", "register-shifts.start", { opening_cash: Number(openingCash || 0), notes: notes || null },
                "Shift started.", "Could not start your shift."),
        /** No PIN: a count that is off goes to a manager on Shifts & counts. */
        endMyShift: (shiftId, payload) =>
            post("my-end", "register-shifts.end", payload, "Shift ended.", "Could not end your shift.", { registerShift: shiftId }),
        reopenMyShift: (shiftId, reason) =>
            post("my-reopen", "register-shifts.reopen", { reason }, "Shift reopened.", "Could not reopen the shift.", { registerShift: shiftId }),
    };
}
