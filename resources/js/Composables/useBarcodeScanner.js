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

    const handleGlobalKeydown = (e) => {
        if (
            ignoreDialogs &&
            e.target?.closest?.(".ant-modal, .ant-drawer, [role='dialog']")
        ) {
            barcodeBuffer.value = "";
            return;
        }

        const tagName = e.target.tagName;
        const isInput = tagName === "INPUT" || tagName === "TEXTAREA";

        const now = Date.now();

        if (e.key === "Enter") {
            if (barcodeBuffer.value.length > 0) {
                // Heuristic: Valid scan if typed fast OR reasonably long
                if (
                    now - lastKeyTime.value < BARCODE_DELAY * 10 ||
                    barcodeBuffer.value.length > 3
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

        // Reset buffer if typing is too slow (likely manual typing),
        // unless we are in an input (where we might want to capture fast typing/paste as scan too)
        // But logic copied from Sales/Index.vue says:
        if (now - lastKeyTime.value > BARCODE_DELAY) {
            if (!isInput) {
                barcodeBuffer.value = ""; // Reset
            }
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
