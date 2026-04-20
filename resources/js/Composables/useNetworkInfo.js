import { ref, computed, onMounted, onBeforeUnmount } from "vue";

/**
 * Network Information API (navigator.connection / mozConnection / webkitConnection).
 *
 * - Chromium often exposes `type`: wifi, cellular, ethernet, none, unknown, etc.
 * - Safari and Firefox may omit `type` or only expose `effectiveType` (4g, 3g, slow-2g).
 *
 * Use for informational UI only. Offline/sync behavior must stay on
 * `navigator.onLine` and `online` / `offline` events.
 */
export function useNetworkInfo() {
    const connectionType = ref(null);
    const effectiveType = ref(null);
    const isNetworkInfoSupported = ref(false);

    let conn = null;

    function getConnection() {
        if (typeof navigator === "undefined") {
            return null;
        }
        return (
            navigator.connection ||
            navigator.mozConnection ||
            navigator.webkitConnection ||
            null
        );
    }

    function readConnection() {
        const c = getConnection();
        if (!c) {
            connectionType.value = null;
            effectiveType.value = null;
            isNetworkInfoSupported.value = false;
            return;
        }
        isNetworkInfoSupported.value = true;
        connectionType.value = c.type ?? null;
        effectiveType.value = c.effectiveType ?? null;
    }

    function onConnectionChange() {
        readConnection();
    }

    onMounted(() => {
        readConnection();
        conn = getConnection();
        if (conn && typeof conn.addEventListener === "function") {
            conn.addEventListener("change", onConnectionChange);
        }
    });

    onBeforeUnmount(() => {
        if (conn && typeof conn.removeEventListener === "function") {
            conn.removeEventListener("change", onConnectionChange);
        }
        conn = null;
    });

    const connectionLabel = computed(() => {
        if (!isNetworkInfoSupported.value) {
            return null;
        }
        const t = connectionType.value;
        if (t != null && t !== "") {
            const map = {
                wifi: "Wi‑Fi",
                cellular: "Cellular",
                ethernet: "Ethernet",
                none: "No network interface",
                unknown: "Unknown",
                bluetooth: "Bluetooth",
                wimax: "WiMAX",
                mixed: "Mixed",
                other: "Other",
            };
            return map[t] || String(t);
        }
        if (effectiveType.value) {
            return `Data (${String(effectiveType.value).toUpperCase()})`;
        }
        return "Unknown";
    });

    const effectiveTypeLabel = computed(() =>
        effectiveType.value
            ? String(effectiveType.value).toUpperCase()
            : null,
    );

    return {
        connectionType,
        effectiveType,
        isNetworkInfoSupported,
        connectionLabel,
        effectiveTypeLabel,
    };
}
