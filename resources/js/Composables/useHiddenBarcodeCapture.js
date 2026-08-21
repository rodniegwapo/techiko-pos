import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";

/**
 * Hidden input capture for keyboard-wedge barcode scanners.
 * Scanners type into the hidden field; Enter copies the value via onScan.
 * Typing in other visible inputs is not treated as a scan.
 *
 * @param {(code: string) => void} onScan
 * @param {{ formContainerRef?: import('vue').Ref<HTMLElement|null> }} [opts]
 */
export function useHiddenBarcodeCapture(onScan, opts = {}) {
    const scannerBuffer = ref("");
    const captureInputRef = ref(null);
    const formContainerRef = opts.formContainerRef ?? ref(null);

    function focusCapture() {
        nextTick(() => {
            const el = captureInputRef.value;
            if (el && typeof el.focus === "function") {
                el.focus({ preventScroll: true });
            }
        });
    }

    function onCaptureEnter(e) {
        e.preventDefault();
        const trimmed = String(scannerBuffer.value || "").trim();
        scannerBuffer.value = "";
        if (!trimmed) {
            return;
        }
        if (typeof onScan === "function") {
            onScan(trimmed);
        }
    }

    function isInteractiveField(el) {
        if (!el || !(el instanceof Element)) {
            return false;
        }
        const tag = el.tagName;
        if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") {
            if (el === captureInputRef.value) {
                return false;
            }
            return true;
        }
        if (el.closest?.(".ant-select,.ant-picker,.ant-input-number,.ant-radio-group")) {
            return true;
        }
        if (el.isContentEditable) {
            return true;
        }
        return false;
    }

    function onDocumentFocusIn(e) {
        const target = e.target;
        if (isInteractiveField(target)) {
            return;
        }
        const container = formContainerRef.value;
        if (
            container &&
            target instanceof Node &&
            container.contains(target) &&
            target !== captureInputRef.value
        ) {
            // Focus stayed inside the form (button, label, etc.) — do not steal
            return;
        }
        focusCapture();
    }

    function onDocumentPointerDown(e) {
        const target = e.target;
        if (!(target instanceof Node)) {
            return;
        }
        if (isInteractiveField(target)) {
            return;
        }
        const container = formContainerRef.value;
        if (container && container.contains(target)) {
            return;
        }
        // Clicked outside the form — reclaim for scanner
        focusCapture();
    }

    onMounted(() => {
        focusCapture();
        document.addEventListener("focusin", onDocumentFocusIn);
        document.addEventListener("pointerdown", onDocumentPointerDown, true);
    });

    onBeforeUnmount(() => {
        document.removeEventListener("focusin", onDocumentFocusIn);
        document.removeEventListener("pointerdown", onDocumentPointerDown, true);
    });

    return {
        scannerBuffer,
        captureInputRef,
        formContainerRef,
        onCaptureEnter,
        focusCapture,
    };
}
