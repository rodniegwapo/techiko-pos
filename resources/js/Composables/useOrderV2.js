// composables/useOrderV2.js
import { ref, computed } from "vue";
import { useDebounceFn, watchDebounced } from "@vueuse/core";
import axios from "axios";
import { useTransactionTimeout } from "./useTransactionTimeout";
import { useOfflineMode } from "./useOfflineMode";
import { useHoldTransaction } from "./useHoldTransaction";

const orders = ref(JSON.parse(localStorage.getItem("orders")) || []);
const orderId = ref(localStorage.getItem("current_order") || null);
const orderDiscountAmount = ref(localStorage.getItem("order_discount_amount"))
const orderDiscountId = ref(localStorage.getItem("order_discount_ids") ?? '')
const isCreatingDraft = ref(false);
let draftPromise = null;

/** 🔹 Utility: calculate discount + subtotal */
function applyDiscountToLine(product, discount) {
    const lineSubtotal = product.price * product.quantity;

    if (!discount) {
        return {
            ...product,
            discount_id: null,
            discount_type: null,
            discount: 0,
            discount_amount: 0,
            subtotal: lineSubtotal,
        };
    }

    let discountAmount = 0;
    if (discount.type === "percentage") {
        const percentage = Math.min(Math.max(discount.value, 0), 100);
        discountAmount = lineSubtotal * (percentage / 100);
    } else if (discount.type === "amount") {
        // 🔹 Multiply by quantity so discount applies per item
        const totalDiscount = discount.value * product.quantity;
        discountAmount = Math.min(Math.max(totalDiscount, 0), lineSubtotal);
    }

    return {
        ...product,
        discount_id: discount.id,
        discount_type: discount.type,
        discount: discount.value,
        discount_amount: discountAmount,
        subtotal: lineSubtotal - discountAmount,
    };
}

/**  Step 1: Create draft */
const createDraft = async () => {
    if (orderId.value) return orderId.value;
    if (isCreatingDraft.value && draftPromise) return draftPromise;

    isCreatingDraft.value = true;
    draftPromise = axios
        .post("/api/sales/draft")
        .then(({ data }) => {
            orderId.value = data.order.id;
            localStorage.setItem("current_order", orderId.value);
            return orderId.value;
        })
        .finally(() => {
            isCreatingDraft.value = false;
            draftPromise = null;
        });

    return draftPromise;
};

/** 🔹 Step 2: Sync draft to DB with offline support */
const syncDraft = useDebounceFn(async () => {
    if (!orderId.value) return;
    
    const { makeRequest } = useOfflineMode();
    
    await makeRequest({
        method: 'POST',
        url: `/api/sales/${orderId.value}/sync`,
        data: {
            items: orders.value.map((item) => ({
                id: item.id,
                quantity: item.quantity,
                price: item.price,
                discount_id: item.discount_id || null,
                discount_amount: item.discount_amount || 0,
                subtotal: item.subtotal,
            })),
        }
    });
}, 1000);

//  Global watcher (sync + localStorage)
watchDebounced(
    orders,
    () => {
        syncDraft();
        localStorage.setItem("orders", JSON.stringify(orders.value));
    },
    { debounce: 1000, deep: true }
);

/** 🔹 Step 3: Finalize */
const finalizeOrder = () => {
    localStorage.removeItem("orders");
    localStorage.removeItem("current_order");
    orderId.value = null;
    orders.value = [];
};

/** 🔹 Add item */
const handleAddOrder = async (product, discount = null) => {
    if (!orderId.value) await createDraft();

    const idx = orders.value.findIndex((p) => p.id === product.id);

    if (idx >= 0) {
        orders.value[idx].quantity += 1;

        // Keep existing discount if no new one is passed
        const activeDiscount = discount || {
            id: orders.value[idx].discount_id,
            type: orders.value[idx].discount_type,
            value: orders.value[idx].discount,
        };

        orders.value[idx] = applyDiscountToLine(
            orders.value[idx],
            activeDiscount
        );
    } else {
        orders.value.push(
            applyDiscountToLine({ ...product, quantity: 1 }, discount)
        );
    }
};

/** 🔹 Subtract item */
const handleSubtractOrder = (product, discount = null) => {
    const idx = orders.value.findIndex((p) => p.id === product.id);
    if (idx >= 0 && orders.value[idx].quantity > 1) {
        orders.value[idx].quantity -= 1;

        //  Keep existing discount if no new one is passed
        const activeDiscount = discount || {
            id: orders.value[idx].discount_id,
            type: orders.value[idx].discount_type,
            value: orders.value[idx].discount,
        };

        orders.value[idx] = applyDiscountToLine(
            orders.value[idx],
            activeDiscount
        );
    }
};

/**  Remove item */
const removeOrder = (product) => {
    orders.value = orders.value.filter((p) => p.id !== product.id);
    if (orders.value.length === 0) finalizeOrder();
};

/** Totals */
const totalAmount = computed(() =>
    orders.value.reduce(
        (sum, i) => sum + (i.subtotal ?? i.quantity * i.price),
        0
    )
);

const formattedTotal = (total) =>
    new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
    }).format(total);

export function useOrders() {
    // Initialize enhanced features
    const timeout = useTransactionTimeout();
    const offline = useOfflineMode();
    const hold = useHoldTransaction();
    
    // Set up timeout callback
    timeout.setClearTransactionCallback(() => {
        finalizeOrder();
    });
    
    // Enhanced finalize with timeout management
    const enhancedFinalizeOrder = () => {
        timeout.stopTimeout();
        finalizeOrder();
    };
    
    // Enhanced add order with activity tracking
    const enhancedHandleAddOrder = async (product, discount = null) => {
        await handleAddOrder(product, discount);
        timeout.updateActivity();
        if (orders.value.length === 1) {
            timeout.startTimeout(); // Start timeout on first item
        }
    };
    
    // Enhanced subtract order with activity tracking
    const enhancedHandleSubtractOrder = (product, discount = null) => {
        handleSubtractOrder(product, discount);
        timeout.updateActivity();
    };
    
    // Enhanced remove order with activity tracking
    const enhancedRemoveOrder = (product) => {
        removeOrder(product);
        timeout.updateActivity();
        if (orders.value.length === 0) {
            timeout.stopTimeout();
        }
    };
    
    // Hold current transaction
    const holdCurrentTransaction = () => {
        const transactionData = {
            orderId: orderId.value,
            orders: orders.value,
            orderDiscountAmount: orderDiscountAmount.value,
            orderDiscountId: orderDiscountId.value,
        };
        
        const heldId = hold.holdTransaction(transactionData);
        if (heldId) {
            enhancedFinalizeOrder();
        }
        return heldId;
    };
    
    // Recall held transaction
    const recallHeldTransaction = (transactionId) => {
        const transaction = hold.recallTransaction(transactionId);
        if (transaction) {
            // Clear current transaction if any
            if (orders.value.length > 0) {
                enhancedFinalizeOrder();
            }
            
            // Restore transaction
            orders.value = [...transaction.orders];
            orderId.value = transaction.orderId;
            orderDiscountAmount.value = transaction.orderDiscountAmount;
            orderDiscountId.value = transaction.orderDiscountId;
            
            // Update localStorage
            localStorage.setItem("orders", JSON.stringify(orders.value));
            localStorage.setItem("current_order", orderId.value);
            localStorage.setItem("order_discount_amount", orderDiscountAmount.value);
            localStorage.setItem("order_discount_ids", orderDiscountId.value);
            
            // Start timeout for recalled transaction
            timeout.startTimeout();
        }
        return transaction;
    };

    return {
        // Original exports
        orders,
        orderId,
        orderDiscountAmount,
        orderDiscountId,
        handleAddOrder: enhancedHandleAddOrder,
        handleSubtractOrder: enhancedHandleSubtractOrder,
        removeOrder: enhancedRemoveOrder,
        createDraft,
        finalizeOrder: enhancedFinalizeOrder,
        totalAmount,
        formattedTotal,
        applyDiscountToLine,
        
        // Enhanced features
        timeout,
        offline,
        hold,
        holdCurrentTransaction,
        recallHeldTransaction,
        
        // Convenience exports
        isOffline: offline.isOffline,
        offlineQueue: offline.offlineQueue,
        heldTransactions: hold.heldTransactions,
        lastActivity: timeout.lastActivity,
    };
}
