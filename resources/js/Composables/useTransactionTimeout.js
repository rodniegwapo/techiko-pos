// composables/useTransactionTimeout.js
import { ref, onMounted, onUnmounted } from "vue";
import { notification } from "ant-design-vue";

export function useTransactionTimeout() {
    const lastActivity = ref(Date.now());
    const TIMEOUT_DURATION = 30 * 60 * 1000; // 30 minutes
    const WARNING_DURATION = 25 * 60 * 1000; // 25 minutes (5 min warning)
    let timeoutTimer = null;
    let warningTimer = null;

    /** Update activity timestamp */
    const updateActivity = () => {
        lastActivity.value = Date.now();
        localStorage.setItem("last_activity", lastActivity.value.toString());
        resetTimers();
    };

    /** Reset timeout timers */
    const resetTimers = () => {
        if (timeoutTimer) clearTimeout(timeoutTimer);
        if (warningTimer) clearTimeout(warningTimer);
        
        // Set warning timer (25 minutes)
        warningTimer = setTimeout(() => {
            showTimeoutWarning();
        }, WARNING_DURATION);
        
        // Set timeout timer (30 minutes)
        timeoutTimer = setTimeout(() => {
            handleTimeout();
        }, TIMEOUT_DURATION);
    };

    /** Show timeout warning */
    const showTimeoutWarning = () => {
        notification.warning({
            message: "Transaction Timeout Warning",
            description: "This transaction will be cleared in 5 minutes due to inactivity.",
            duration: 10,
            key: 'timeout-warning'
        });
    };

    /** Handle timeout */
    const handleTimeout = () => {
        const timeSinceActivity = Date.now() - lastActivity.value;
        if (timeSinceActivity >= TIMEOUT_DURATION) {
            notification.info({
                message: "Transaction Cleared",
                description: "Transaction has been automatically cleared due to inactivity.",
                duration: 5,
            });
            clearTransaction();
        }
    };

    /** Clear transaction callback - to be set by parent */
    let clearTransaction = () => {
        console.log("Transaction cleared due to timeout");
    };

    /** Set the clear transaction callback */
    const setClearTransactionCallback = (callback) => {
        clearTransaction = callback;
    };

    /** Start timeout monitoring */
    const startTimeout = () => {
        updateActivity();
        resetTimers();
    };

    /** Stop timeout monitoring */
    const stopTimeout = () => {
        if (timeoutTimer) clearTimeout(timeoutTimer);
        if (warningTimer) clearTimeout(warningTimer);
        localStorage.removeItem("last_activity");
    };

    /** Check for abandoned transactions on mount */
    const checkAbandonedTransaction = () => {
        const savedActivity = localStorage.getItem("last_activity");
        if (savedActivity) {
            const timeSinceActivity = Date.now() - parseInt(savedActivity);
            if (timeSinceActivity >= TIMEOUT_DURATION) {
                handleTimeout();
                return true; // Transaction was abandoned
            } else {
                lastActivity.value = parseInt(savedActivity);
                resetTimers();
            }
        }
        return false; // No abandoned transaction
    };

    onMounted(() => {
        checkAbandonedTransaction();
    });

    onUnmounted(() => {
        stopTimeout();
    });

    return {
        updateActivity,
        startTimeout,
        stopTimeout,
        setClearTransactionCallback,
        checkAbandonedTransaction,
        lastActivity,
    };
}
