import { getCurrentInstance } from "vue";

export function useEmits() {
    const vm = getCurrentInstance();
    const emit = vm?.emit || (() => {});

    /** Emit update:modelValue */
    function emitUpdate(key, value) {
        emit("update:" + key, value);
    }

    /** Emit close event */
    function emitClose(payload = null) {
        emit("close", payload);
    }

    /** Emit submit event */
    function emitSubmit(payload) {
        emit("submit", payload);
    }

    /** Emit generic event */
    function emitEvent(event, payload) {
        emit(event, payload);
    }
    

    return {
        emitUpdate,
        emitClose,
        emitSubmit,
        emitEvent,
    };
}
