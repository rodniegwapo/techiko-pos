// composables/useConfirmDelete.js
import { computed, createVNode } from "vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import { router } from "@inertiajs/vue3";
import { useGlobalVariables } from "./useGlobalVariable";
import { useEmits } from "./useEmits";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import isocalendar from "dayjs/plugin/isoWeek";
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
            errors.value = {};
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

    return {
        confirmDelete,
        inertiaProgressLifecyle,
        showModal,
        getDeviceId,
        startDateFormat,
        endDateFormat,
        formattedTotal,
        formattedPercent
    };
}
