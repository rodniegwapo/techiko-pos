// composables/useHoldTransaction.js
import { ref } from "vue";
import { notification } from "ant-design-vue";

export function useHoldTransaction() {
    const heldTransactions = ref(JSON.parse(localStorage.getItem("held_transactions")) || []);
    const maxHeldTransactions = 10; // Limit to prevent storage issues

    /** Hold current transaction */
    const holdTransaction = (transactionData) => {
        if (!transactionData || !transactionData.orders || transactionData.orders.length === 0) {
            notification.warning({
                message: "No Transaction",
                description: "There's no active transaction to hold.",
            });
            return null;
        }

        // Check if we've reached the limit
        if (heldTransactions.value.length >= maxHeldTransactions) {
            notification.warning({
                message: "Hold Limit Reached",
                description: `Maximum ${maxHeldTransactions} transactions can be held. Please clear some held transactions first.`,
            });
            return null;
        }

        const transactionId = `hold_${Date.now()}`;
        const currentUser = JSON.parse(localStorage.getItem("user") || "{}");
        
        const heldTransaction = {
            id: transactionId,
            orderId: transactionData.orderId,
            orders: [...transactionData.orders],
            orderDiscountAmount: transactionData.orderDiscountAmount,
            orderDiscountId: transactionData.orderDiscountId,
            timestamp: Date.now(),
            cashier: currentUser.name || "Unknown",
            cashierId: currentUser.id || null,
        };

        heldTransactions.value.push(heldTransaction);
        localStorage.setItem("held_transactions", JSON.stringify(heldTransactions.value));

        notification.success({
            message: "Transaction Held",
            description: `Transaction #${transactionId.slice(-6)} has been held successfully.`,
            duration: 3,
        });

        return transactionId;
    };

    /** Recall held transaction */
    const recallTransaction = (transactionId) => {
        const index = heldTransactions.value.findIndex(t => t.id === transactionId);
        if (index === -1) {
            notification.error({
                message: "Transaction Not Found",
                description: "The requested transaction could not be found.",
            });
            return null;
        }

        const transaction = heldTransactions.value[index];
        
        // Remove from held transactions
        heldTransactions.value.splice(index, 1);
        localStorage.setItem("held_transactions", JSON.stringify(heldTransactions.value));

        notification.success({
            message: "Transaction Recalled",
            description: `Transaction #${transactionId.slice(-6)} has been recalled successfully.`,
            duration: 3,
        });

        return {
            orderId: transaction.orderId,
            orders: transaction.orders,
            orderDiscountAmount: transaction.orderDiscountAmount,
            orderDiscountId: transaction.orderDiscountId,
        };
    };

    /** Clear held transaction */
    const clearHeldTransaction = (transactionId) => {
        const index = heldTransactions.value.findIndex(t => t.id === transactionId);
        if (index === -1) {
            notification.error({
                message: "Transaction Not Found",
                description: "The requested transaction could not be found.",
            });
            return false;
        }

        heldTransactions.value.splice(index, 1);
        localStorage.setItem("held_transactions", JSON.stringify(heldTransactions.value));

        notification.success({
            message: "Held Transaction Cleared",
            description: "The held transaction has been removed.",
            duration: 3,
        });

        return true;
    };

    /** Clear all held transactions */
    const clearAllHeldTransactions = () => {
        const count = heldTransactions.value.length;
        heldTransactions.value = [];
        localStorage.removeItem("held_transactions");

        notification.success({
            message: "All Transactions Cleared",
            description: `${count} held transactions have been cleared.`,
            duration: 3,
        });
    };

    /** Get held transactions for current cashier */
    const getHeldTransactionsForCashier = (cashierId) => {
        return heldTransactions.value.filter(t => t.cashierId === cashierId);
    };

    /** Get transaction total */
    const getTransactionTotal = (transaction) => {
        return transaction.orders.reduce(
            (sum, item) => sum + (item.subtotal || item.price * item.quantity),
            0
        );
    };

    /** Format transaction for display */
    const formatTransactionForDisplay = (transaction) => {
        return {
            ...transaction,
            displayId: transaction.id.slice(-6),
            total: getTransactionTotal(transaction),
            itemCount: transaction.orders.length,
            formattedTimestamp: new Date(transaction.timestamp).toLocaleString(),
        };
    };

    /** Clean up old held transactions (older than 24 hours) */
    const cleanupOldTransactions = () => {
        const twentyFourHoursAgo = Date.now() - (24 * 60 * 60 * 1000);
        const initialCount = heldTransactions.value.length;
        
        heldTransactions.value = heldTransactions.value.filter(
            t => t.timestamp > twentyFourHoursAgo
        );
        
        const removedCount = initialCount - heldTransactions.value.length;
        
        if (removedCount > 0) {
            localStorage.setItem("held_transactions", JSON.stringify(heldTransactions.value));
            notification.info({
                message: "Old Transactions Cleaned",
                description: `${removedCount} transactions older than 24 hours were removed.`,
                duration: 3,
            });
        }
    };

    // Auto-cleanup on load
    cleanupOldTransactions();

    return {
        heldTransactions,
        holdTransaction,
        recallTransaction,
        clearHeldTransaction,
        clearAllHeldTransactions,
        getHeldTransactionsForCashier,
        getTransactionTotal,
        formatTransactionForDisplay,
        cleanupOldTransactions,
        maxHeldTransactions,
    };
}
