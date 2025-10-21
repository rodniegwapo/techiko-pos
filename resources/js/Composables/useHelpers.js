// composables/useConfirmDelete.js
import { computed, createVNode } from "vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import { router } from "@inertiajs/vue3";
import { useGlobalVariables } from "./useGlobalVariable";
import { useEmits } from "./useEmits";
import { useDomainRoutes } from "./useDomainRoutes";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import isocalendar from "dayjs/plugin/isoWeek";
import { Modal, notification } from "ant-design-vue";
dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.extend(isocalendar);
/**
 * Composable for showing delete confirmation modals
 *
 * Example usage:
 * const { confirmDelete } = useConfirmDelete();
 * confirmDelete("categories.destroy", { id: record.id }, "Do you want to delete this item?");
 */

const { formData, spinning, errors, openModal, isEdit } = useGlobalVariables();
const { emitClose } = useEmits();
const { getRoute } = useDomainRoutes();

export function useHelpers() {
    const showModal = (modalName) => {
        formData.value = {};
        openModal.value = true;
        isEdit.value = false;
    };

    const inertiaProgressLifecyle = {
        onSuccess: (page) => {
            // Check if there's an error message first
            if (page?.props?.flash?.error) {
                notification.error({
                    message: "Error",
                    description: page.props.flash.error,
                });
                return;
            }
            
            openModal.value = false;
            formData.value = {};
            errors.value = {};
            
            // Show success notification - check multiple possible locations for flash message
            const successMessage = page?.props?.flash?.success || 
                                 page?.props?.success || 
                                 'Operation completed successfully';
            
            notification.success({
                message: "Success",
                description: successMessage,
            });
        },
        onStart: () => {
            spinning.value = true;
        },
        onError: (error) => {
            errors.value = error;
            notification.error({
                message: "Error",
                description: "Operation failed. Please try again.",
            });
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
                        const routeUrl = getRoute(routeName, params);
                        
                        // Check if the route URL is valid
                        if (routeUrl === '#' || !routeUrl || routeUrl.includes('#')) {
                            console.error('Invalid route URL generated:', routeUrl);
                            notification.error({
                                message: "Error",
                                description: "Invalid route generated. Please try again.",
                            });
                            reject(new Error('Invalid route'));
                            innerReject();
                            return;
                        }
                        
                        router.delete(routeUrl, {
                            onSuccess: (page) => {
                                // Check if the backend returned an error message (redirect with error)
                                if (page.props?.flash?.error) {
                                    notification.error({
                                        message: "Cannot Delete",
                                        description: page.props.flash.error,
                                    });
                                    reject(new Error(page.props.flash.error));
                                    innerReject();
                                    return;
                                }
                                
                                // Check if the backend actually returned a success message
                                if (page.props?.flash?.success) {
                                    notification.success({
                                        message: "Success",
                                        description: page.props.flash.success,
                                    });
                                } else {
                                    notification.success({
                                        message: "Success",
                                        description: "Item deleted successfully!",
                                    });
                                }
                                resolve(page);
                                innerResolve();
                            },
                            onError: (errors) => {
                                let errorMessage = "Failed to delete item.";
                                
                                // Check if there's a specific error message from the backend
                                if (errors && typeof errors === 'object') {
                                    if (errors.message) {
                                        errorMessage = errors.message;
                                    } else if (errors.error) {
                                        errorMessage = errors.error;
                                    } else if (Array.isArray(errors) && errors.length > 0) {
                                        errorMessage = errors[0];
                                    }
                                }
                                
                                notification.error({
                                    message: "Error",
                                    description: errorMessage,
                                });
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

    const getDeviceId = computed(() => {
        let deviceId = localStorage.getItem("device_id");

        return deviceId;
    });

    const startDateFormat = (date) => {
        return dayjs(`${date.format("YYYY-MM-DD")}T00:00:00`).format(
            "YYYY-MM-DD HH:mm:ss"
        );
    };

    const endDateFormat = (date) => {
        return dayjs(`${date.format("YYYY-MM-DD")}T23:59:59`).format(
            "YYYY-MM-DD HH:mm:ss"
        );
    };

    const formattedTotal = (total) => {
        const formattedTotal = new Intl.NumberFormat("en-PH", {
            style: "currency",
            currency: "PHP",
        }).format(total);

        return formattedTotal;
    };

    const formattedPercent = (value) => {
        return new Intl.NumberFormat("en-PH", {
            style: "percent",
            minimumFractionDigits: 0, // optional, to control decimals
            maximumFractionDigits: 2,
        }).format(value / 100);
    };

    // Format currency (alias for formattedTotal)
    const formatCurrency = (value) => {
        if (value === null || value === undefined || isNaN(value)) {
            return "₱0.00";
        }
        return new Intl.NumberFormat("en-PH", {
            style: "currency",
            currency: "PHP",
        }).format(value);
    };

    // Format date
    const formatDate = (date) => {
        if (!date) return "N/A";
        return dayjs(date).format("MMMM DD, YYYY");
    };

    // Format date and time
    const formatDateTime = (date) => {
        if (!date) return "N/A";
        return dayjs(date).format("MMMM DD, YYYY HH:mm");
    };

    // Show notification
    const showNotification = (type, title, message) => {
        // You can implement this with ant-design-vue notification

        return notification[type]({
            message: title,
            description: message,
        });
    };

    return {
        confirmDelete,
        inertiaProgressLifecyle,
        showModal,
        getDeviceId,
        startDateFormat,
        endDateFormat,
        formattedTotal,
        formattedPercent,
        formatCurrency,
        formatDate,
        formatDateTime,
        showNotification,
    };
}
