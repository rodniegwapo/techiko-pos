<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { Html5QrcodeScanner, Html5QrcodeSupportedFormats } from "html5-qrcode";
import { Modal } from "ant-design-vue";
import { CameraOutlined, StopOutlined } from "@ant-design/icons-vue";

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["update:open", "scanned"]);

// Use a unique ID for the scanner element to avoid conflicts if multiple exist
const scannerId = "reader-" + Math.random().toString(36).substring(2, 9);
const scanner = ref(null);
const scannerActive = ref(false);

const onScanSuccess = (decodedText, decodedResult) => {
    // Play a beep sound (optional, good for feedback)
    // const audio = new Audio('/beep.mp3'); // if you have one
    // audio.play().catch(e => {});

    emit("scanned", decodedText);

    // Close modal on success? Or keep open for multiple?
    // Let's close it for now to be safe, or user handling
    handleClose();
};

const onScanFailure = (error) => {
    // handle scan failure, usually better to ignore and keep scanning.
    // console.warn(`Code scan error = ${error}`);
};

const startScanner = () => {
    // Wait for DOM to be ready inside modal
    setTimeout(() => {
        if (scanner.value) {
            // already running
            return;
        }

        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.QR_CODE,
            ],
        };

        scanner.value = new Html5QrcodeScanner(
            scannerId,
            config,
            /* verbose= */ false,
        );
        scanner.value.render(onScanSuccess, onScanFailure);
        scannerActive.value = true;
    }, 100);
};

const stopScanner = () => {
    if (scanner.value) {
        scanner.value.clear().catch((error) => {
            console.error("Failed to clear html5-qrcode scanner. ", error);
        });
        scanner.value = null;
        scannerActive.value = false;
    }
};

const handleClose = () => {
    stopScanner();
    emit("update:open", false);
};

// Start scanner when modal opens
// We trigger this from the watcher in the parent or use logic here if possible.
// Since Ant Modal v-model:visible destroys/creates content, we might need a watcher.
import { watch } from "vue";
watch(
    () => props.open,
    (newVal) => {
        if (newVal) {
            startScanner();
        } else {
            stopScanner();
        }
    },
);

onBeforeUnmount(() => {
    stopScanner();
});
</script>

<template>
    <Modal
        :visible="open"
        title="Scan Barcode"
        @cancel="handleClose"
        :footer="null"
        width="500px"
        destroyOnClose
    >
        <div class="flex flex-col items-center justify-center p-4">
            <div :id="scannerId" class="w-full"></div>
            <p v-if="!scannerActive" class="text-gray-500 mt-4">
                Initializing Camera...
            </p>
            <p v-else class="text-gray-500 mt-2 text-sm text-center">
                Point camera at a barcode to scan.
            </p>
        </div>
    </Modal>
</template>

<style scoped>
/* Optional custom styling for the scanner box */
:deep(#reader__scan_region) {
    background: white;
}
:deep(#reader__dashboard_section_csr button) {
    @apply px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition duration-150 ease-in-out cursor-pointer;
}
</style>
