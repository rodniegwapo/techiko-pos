// composables/useOfflineMode.js
import { ref, onMounted, onUnmounted } from "vue";
import { notification } from "ant-design-vue";
import axios from "axios";

export function useOfflineMode() {
    const isOffline = ref(!navigator.onLine);
    const offlineQueue = ref(JSON.parse(localStorage.getItem("offline_queue")) || []);
    const isProcessingQueue = ref(false);

    /** Handle online event */
    const handleOnline = () => {
        isOffline.value = false;
        notification.success({
            message: "Back Online",
            description: "Connection restored. Syncing pending changes...",
            duration: 3,
        });
        processOfflineQueue();
    };

    /** Handle offline event */
    const handleOffline = () => {
        isOffline.value = true;
        notification.warning({
            message: "Offline Mode",
            description: "Working offline. Changes will sync when connection is restored.",
            duration: 0, // Don't auto-close
            key: 'offline-mode'
        });
    };

    /** Add action to offline queue */
    const addToOfflineQueue = (url, data, method = 'POST') => {
        const action = {
            id: Date.now() + Math.random(),
            url,
            data,
            method,
            timestamp: Date.now(),
            retries: 0
        };
        
        offlineQueue.value.push(action);
        localStorage.setItem("offline_queue", JSON.stringify(offlineQueue.value));
        
        notification.info({
            message: "Action Queued",
            description: "Action saved for when connection is restored.",
            duration: 2,
        });
    };

    /** Process offline queue */
    const processOfflineQueue = async () => {
        if (offlineQueue.value.length === 0 || isProcessingQueue.value) return;
        
        isProcessingQueue.value = true;
        const queue = [...offlineQueue.value];
        const failedActions = [];
        
        for (const action of queue) {
            try {
                await axios({
                    method: action.method,
                    url: action.url,
                    data: action.data
                });
                
                // Remove successful action from queue
                offlineQueue.value = offlineQueue.value.filter(item => item.id !== action.id);
            } catch (error) {
                console.error("Failed to sync offline action:", error);
                
                // Increment retry count
                action.retries = (action.retries || 0) + 1;
                
                // Keep in queue if retries < 3
                if (action.retries < 3) {
                    failedActions.push(action);
                } else {
                    // Remove after 3 failed attempts
                    offlineQueue.value = offlineQueue.value.filter(item => item.id !== action.id);
                    notification.error({
                        message: "Sync Failed",
                        description: "Some changes could not be synced after multiple attempts.",
                        duration: 5,
                    });
                }
            }
        }
        
        // Update queue with failed actions
        if (failedActions.length > 0) {
            offlineQueue.value = failedActions;
        }
        
        localStorage.setItem("offline_queue", JSON.stringify(offlineQueue.value));
        isProcessingQueue.value = false;
        
        if (offlineQueue.value.length === 0) {
            notification.success({
                message: "Sync Complete",
                description: "All offline changes have been synced successfully.",
                duration: 3,
            });
        }
    };

    /** Enhanced axios request with offline support */
    const makeRequest = async (config) => {
        if (isOffline.value) {
            addToOfflineQueue(config.url, config.data, config.method);
            return Promise.resolve({ data: { offline: true } });
        }
        
        try {
            return await axios(config);
        } catch (error) {
            if (!navigator.onLine) {
                handleOffline();
                addToOfflineQueue(config.url, config.data, config.method);
                return Promise.resolve({ data: { offline: true } });
            }
            throw error;
        }
    };

    /** Clear offline queue */
    const clearOfflineQueue = () => {
        offlineQueue.value = [];
        localStorage.removeItem("offline_queue");
        notification.info({
            message: "Queue Cleared",
            description: "Offline queue has been cleared.",
        });
    };

    onMounted(() => {
        // Setup event listeners
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
        
        // Process any existing queue if online
        if (navigator.onLine && offlineQueue.value.length > 0) {
            processOfflineQueue();
        }
    });

    onUnmounted(() => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    });

    return {
        isOffline,
        offlineQueue,
        isProcessingQueue,
        addToOfflineQueue,
        processOfflineQueue,
        makeRequest,
        clearOfflineQueue,
    };
}
