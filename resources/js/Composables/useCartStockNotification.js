import { notification } from "ant-design-vue";

/**
 * Show Ant Design warning when Laravel cart endpoints return 422 with errors.stock.
 *
 * @param {unknown} error Axios-like error from failed request
 * @returns {boolean} true if a stock warning was shown
 */
export function notifyInsufficientStock(error) {
    const status = error?.response?.status;
    const stockMessages = error?.response?.data?.errors?.stock;

    if (
        status === 422 &&
        Array.isArray(stockMessages) &&
        stockMessages.length > 0
    ) {
        const description =
            typeof stockMessages[0] === "string"
                ? stockMessages[0]
                : String(stockMessages[0]);

        notification.warning({
            message: "Insufficient stock",
            description,
        });

        return true;
    }

    return false;
}
