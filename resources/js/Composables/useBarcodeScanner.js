import { ref, onMounted, onBeforeUnmount } from "vue";

/**
 * @param {Function} onScan
 * @param {{ ignoreDialogs?: boolean }} options  ignoreDialogs: keys typed inside a modal or drawer
 *        (e.g. a void PIN) are not treated as a scan.
 */
export function useBarcodeScanner(onScan, { ignoreDialogs = false } = {}) {
    const barcodeBuffer = ref("");
    const lastKeyTime = ref(0);
    const BARCODE_DELAY = 50; // ms
    const MIN_SCAN_LENGTH = 3;

    const handleGlobalKeydown = (e) => {
        if (
            ignoreDialogs &&
            e.target?.closest?.(".ant-modal, .ant-drawer, [role='dialog']")
        ) {
            barcodeBuffer.value = "";
            return;
        }

        const now = Date.now();

        if (e.key === "Enter") {
            if (barcodeBuffer.value.length > 0) {
                // A scan: at least MIN_SCAN_LENGTH keys typed scanner-fast, ending in Enter. Amounts
                // typed by hand (e.g. "1000" in Amount received) reset the buffer on every key.
                if (
                    barcodeBuffer.value.length >= MIN_SCAN_LENGTH &&
                    now - lastKeyTime.value < BARCODE_DELAY * 10
                ) {
                    // Prevent default form submission or other Enter behaviors
                    e.preventDefault();
                    if (typeof onScan === 'function') {
                        onScan(barcodeBuffer.value);
                    }
                }
                barcodeBuffer.value = "";
            }
            return;
        }

        // Filter out special keys like Control, Shift, etc.
        if (e.key.length > 1) return;

        // Typing slower than a scanner is manual typing, in an input or not: start over.
        if (now - lastKeyTime.value > BARCODE_DELAY) {
            barcodeBuffer.value = "";
        }

        // Always append key to buffer to track potential scans
        barcodeBuffer.value += e.key;
        lastKeyTime.value = now;
    };

    onMounted(() => {
        window.addEventListener("keydown", handleGlobalKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("keydown", handleGlobalKeydown);
    });

    return {
        barcodeBuffer
    };
}
