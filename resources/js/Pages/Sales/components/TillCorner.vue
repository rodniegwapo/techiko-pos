<script setup>
import { computed } from "vue";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import TimeclockButton from "./TimeclockButton.vue";

/**
 * The top-right corner of the Sales screen: the signed-in user's clock ("In since 8:02 AM") and
 * their drawer ("Open my drawer", or when it opened). Below the store switcher for admins.
 */
const props = defineProps({
    shiftStatus: { type: Object, default: null },
    locationId: { type: [Number, String], default: null },
    canPunch: { type: Boolean, default: false },
});
const emit = defineEmits(["open-drawer", "punched"]);
const { getRoute } = useDomainRoutes();

const s = computed(() => props.shiftStatus ?? {});

/** What the drawer button says and does, from the drawer's state. */
const drawer = computed(() => {
    if (!props.shiftStatus) return null;
    if (s.value.is_closed) {
        // Without the permission the dialog says to ask a manager.
        return { label: "Reopen the day", primary: true, act: () => emit("open-drawer") };
    }
    if (s.value.overdue_since) {
        return { label: "End it on Today's drawer", primary: true, act: () => router.visit(getRoute("wallet.drawer")) };
    }
    if (s.value.is_open) {
        const at = s.value.opened_at ? ` · ${dayjs(s.value.opened_at).format("h:mm A")}` : "";
        return { label: `Drawer open${at}`, primary: false, open: true, act: () => router.visit(getRoute("wallet.drawer")) };
    }
    // Even when they can't open one, the dialog says why (no permission, a count waiting for review).
    return { label: "Open my drawer", primary: true, act: () => emit("open-drawer") };
});
</script>

<template>
    <div v-if="canPunch || drawer" class="flex items-center gap-2" data-testid="till-corner">
        <TimeclockButton
            v-if="canPunch"
            :location-id="locationId"
            :status="shiftStatus?.clock"
            @punched="(data) => emit('punched', data)"
        />
        <a-button
            v-if="drawer"
            size="small"
            :type="drawer.primary ? 'primary' : 'default'"
            :class="{ '!border-teal-300 !bg-teal-50 !text-teal-800': drawer.open }"
            data-testid="till-drawer-action"
            @click="drawer.act"
        >
            {{ drawer.label }}
        </a-button>
    </div>
</template>
