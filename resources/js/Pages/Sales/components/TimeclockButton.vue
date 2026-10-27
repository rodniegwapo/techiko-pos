<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from "vue";
import axios from "axios";
import dayjs from "dayjs";
import { IconClock } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/**
 * Clock in / out on the POS. Whoever stands at the till types their own PIN: it clocks them in
 * when they are off the clock and out when they are on it. The till stays signed in as it is.
 */
const props = defineProps({
    locationId: { type: [Number, String], default: null },
    /** "classic" (gray header button) or "coffeeshop" (the modern layout's hero). */
    variant: { type: String, default: "classic" },
});

const { getRoute } = useDomainRoutes();

const open = ref(false);
const pin = ref("");
const error = ref("");
const busy = ref(false);
const result = ref(null);
const pinInput = ref(null);
let closeTimer = null;

function show() {
    pin.value = "";
    error.value = "";
    result.value = null;
    open.value = true;
    nextTick(() => pinInput.value?.focus());
}

function close() {
    clearTimeout(closeTimer);
    open.value = false;
}

function press(key) {
    error.value = "";
    if (key === "clear") pin.value = "";
    else if (key === "back") pin.value = pin.value.slice(0, -1);
    else if (pin.value.length < 12) pin.value += key;
}

async function submit() {
    if (!pin.value || busy.value) return;
    busy.value = true;
    error.value = "";
    try {
        const { data } = await axios.post(getRoute("timesheets.punch"), {
            pin: pin.value,
            location_id: props.locationId ?? undefined,
        });
        result.value = data;
        pin.value = "";
        // Back to the till on its own; the next person can open it again.
        clearTimeout(closeTimer);
        closeTimer = setTimeout(close, 5000);
    } catch (e) {
        error.value = e?.response?.data?.errors?.pin?.[0]
            ?? e?.response?.data?.errors?.location_id?.[0]
            ?? e?.response?.data?.message
            ?? "Could not clock in. Try again.";
        pin.value = "";
    } finally {
        busy.value = false;
    }
}

const hours = (minutes) => {
    const m = Math.max(0, Math.round(minutes || 0));
    const h = Math.floor(m / 60);
    return h ? `${h}h ${String(m % 60).padStart(2, "0")}m` : `${m % 60}m`;
};

const headline = computed(() => {
    if (!result.value) return "";
    const name = result.value.user.name;
    const at = dayjs(result.value.at).format("h:mm A");
    return result.value.action === "in" ? `${name} clocked in at ${at}` : `${name} clocked out at ${at}`;
});

const keys = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "clear", "0", "back"];

onBeforeUnmount(() => clearTimeout(closeTimer));
</script>

<template>
    <a-button
        v-if="variant === 'classic'"
        size="small"
        class="flex items-center gap-1"
        data-testid="timeclock-open"
        @click="show"
    >
        <IconClock :size="16" />
        Clock in / out
    </a-button>
    <button
        v-else
        type="button"
        class="mt-2 inline-flex items-center gap-1 rounded-full border border-[var(--cs-line,#e5e7eb)] bg-white/70 px-3 py-1 text-xs font-medium text-[var(--cs-ink)] hover:bg-white"
        data-testid="timeclock-open"
        @click="show"
    >
        <IconClock :size="14" />
        Clock in / out
    </button>

    <a-modal
        :visible="open"
        title="Clock in / out"
        :footer="null"
        :width="340"
        destroy-on-close
        @cancel="close"
    >
        <div v-if="result" class="py-4 text-center" data-testid="timeclock-result">
            <div
                class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full"
                :class="result.action === 'in' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'"
            >
                <IconClock :size="26" />
            </div>
            <p class="m-0 text-base font-semibold text-gray-900">{{ headline }}</p>
            <p v-if="result.action === 'out'" class="mt-1 mb-0 text-sm text-gray-600">
                This shift {{ hours(result.worked_minutes) }} · {{ hours(result.minutes_today) }} today
            </p>
            <p v-else-if="result.minutes_today > 0" class="mt-1 mb-0 text-sm text-gray-600">
                {{ hours(result.minutes_today) }} worked today so far
            </p>
            <a-alert v-if="result.note" type="warning" :message="result.note" class="mt-3 text-left" />
            <a-button class="mt-4" @click="close">Done</a-button>
        </div>

        <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
            <p class="m-0 text-sm text-gray-600">Type your own PIN.</p>
            <input
                ref="pinInput"
                v-model="pin"
                type="password"
                inputmode="numeric"
                autocomplete="off"
                maxlength="12"
                aria-label="Your PIN"
                class="h-12 w-full rounded-md border border-gray-300 text-center text-2xl tracking-[0.4em] focus:border-emerald-500 focus:outline-none"
                data-testid="timeclock-pin"
            />
            <p v-if="error" class="m-0 text-sm text-red-600" data-testid="timeclock-error">{{ error }}</p>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="k in keys"
                    :key="k"
                    type="button"
                    class="h-12 rounded-md border border-gray-200 bg-gray-50 text-lg font-medium text-gray-800 hover:bg-gray-100 active:bg-gray-200"
                    :aria-label="k === 'clear' ? 'Clear' : k === 'back' ? 'Delete last digit' : k"
                    @click="press(k)"
                >
                    {{ k === "clear" ? "C" : k === "back" ? "⌫" : k }}
                </button>
            </div>
            <a-button type="primary" html-type="submit" size="large" :loading="busy" :disabled="!pin" data-testid="timeclock-submit">
                OK
            </a-button>
        </form>
    </a-modal>
</template>
