<script setup>
import { computed, watch } from "vue";
import dayjs from "dayjs";

/**
 * "Clock me in too" when opening your drawer, "Clock me out too" when closing it. Your own PIN,
 * like the Clock in / out keypad: the drawer action and the punch go together or not at all.
 */
const props = defineProps({
    /** "in" when opening the drawer, "out" when closing it. */
    mode: { type: String, default: "in" },
    /** { on_the_clock, since, has_pin } from the server. */
    status: { type: Object, default: null },
    /** { enabled: boolean, pin: string } */
    modelValue: { type: Object, default: () => ({ enabled: false, pin: "" }) },
    error: { type: String, default: "" },
});
const emit = defineEmits(["update:modelValue"]);

const onClock = computed(() => !!props.status?.on_the_clock);
const hasPin = computed(() => !!props.status?.has_pin);
const since = computed(() => (props.status?.since ? dayjs(props.status.since).format("h:mm A") : null));

/** Whether there is anything to offer: clock in when off the clock, clock out when on it. */
const offer = computed(() => !!props.status && (props.mode === "in" ? !onClock.value : onClock.value));

const set = (patch) => emit("update:modelValue", { ...props.modelValue, ...patch });

// Clocking in is ticked by default; clocking out isn't (they may close the drawer and keep working).
watch(
    () => [offer.value, hasPin.value, props.mode],
    () => set({ enabled: offer.value && hasPin.value && props.mode === "in", pin: "" }),
    { immediate: true },
);
</script>

<template>
    <p v-if="mode === 'in' && onClock" class="m-0 text-xs text-gray-500" data-testid="clock-along-on">
        You're clocked in<template v-if="since"> since {{ since }}</template>.
    </p>

    <div v-else-if="offer" class="rounded-md border border-gray-200 bg-gray-50 p-3" data-testid="clock-along">
        <p v-if="!hasPin" class="m-0 text-xs text-gray-600" data-testid="clock-along-no-pin">
            You don't have a PIN yet, so you can't clock {{ mode }} here. Ask your manager to set one, or set it on your Profile.
        </p>
        <template v-else>
            <a-checkbox
                :checked="modelValue.enabled"
                data-testid="clock-along-toggle"
                @change="(e) => set({ enabled: e.target.checked, pin: '' })"
            >
                {{ mode === "in" ? "Clock me in too" : "Clock me out too" }}
            </a-checkbox>
            <div v-if="modelValue.enabled" class="mt-2">
                <a-input-password
                    :value="modelValue.pin"
                    inputmode="numeric"
                    autocomplete="off"
                    :maxlength="12"
                    placeholder="Your PIN"
                    aria-label="Your PIN"
                    data-testid="clock-along-pin"
                    @update:value="(v) => set({ pin: (v || '').replace(/\D/g, '') })"
                />
                <p v-if="error" class="mb-0 mt-1 text-xs text-red-600" data-testid="clock-along-error">{{ error }}</p>
            </div>
        </template>
    </div>
</template>
