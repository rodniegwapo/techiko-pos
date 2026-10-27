<script setup>
import { computed, reactive, ref } from "vue";
import axios from "axios";
import { message } from "ant-design-vue";
import { IconArmchair, IconChefHat, IconLayoutGrid } from "@tabler/icons-vue";
import KitchenTicket from "@/Components/Receipt/KitchenTicket.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { allowsDecimal, formatQuantity, quantityStep, toQuantity } from "@/Utils/quantity";

/**
 * The open tab, above the cart: which table or name, and what can be done with it. The cart below
 * is the tab's own (adding items and paying work as usual).
 */
const props = defineProps({
    sale: { type: Object, required: true },
    /** The cart lines as Sales/Index shows them (with sale_item_id). */
    orders: { type: Array, default: () => [] },
});
const emit = defineEmits(["changed", "left", "tables"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const label = computed(() =>
    [props.sale.dining_table?.name, props.sale.tab_name].filter(Boolean).join(" · ") || `Tab #${props.sale.id}`,
);

const errorOf = (e, fallback) => {
    const errors = e?.response?.data?.errors ?? {};
    return Object.values(errors)[0]?.[0] ?? e?.response?.data?.message ?? fallback;
};

const busy = ref(false);
async function run(fn, fallback) {
    busy.value = true;
    try {
        return await fn();
    } catch (e) {
        message.error(errorOf(e, fallback));
        return null;
    } finally {
        busy.value = false;
    }
}

// Send to kitchen: the ticket comes back to be printed.
const ticket = ref(null);
const printId = computed(() => `kitchen-ticket-${ticket.value?.id ?? "x"}`);
async function send() {
    const data = await run(() => axios.post(getRoute("tabs.send", { tab: props.sale.id })).then((r) => r.data), "Could not send to the kitchen.");
    if (data) {
        ticket.value = data.ticket;
        emit("changed");
    }
}

// Earlier tickets, to print again.
const tickets = ref(null);
async function showTickets() {
    const data = await run(() => axios.get(getRoute("tabs.tickets", { tab: props.sale.id })).then((r) => r.data), "Could not load the tickets.");
    if (data) tickets.value = data.tickets;
}

// The other tables and tabs, for Move and Merge.
const places = ref({ tables: [], tabs: [] });
async function loadPlaces() {
    const data = await run(() => axios.get(getRoute("tabs.index")).then((r) => r.data), "Could not load the tables.");
    if (data) places.value = data;
    return !!data;
}

const moveOpen = ref(false);
const moveTo = ref(null);
async function openMove() {
    if (await loadPlaces()) {
        moveTo.value = props.sale.dining_table_id ?? null;
        moveOpen.value = true;
    }
}
async function move() {
    const data = await run(() => axios.post(getRoute("tabs.move", { tab: props.sale.id }), { dining_table_id: moveTo.value }), "Could not move the tab.");
    if (data) {
        moveOpen.value = false;
        message.success("Tab moved.");
        emit("changed");
    }
}

const mergeOpen = ref(false);
const mergeInto = ref(null);
const otherTabs = computed(() => places.value.tabs.filter((t) => t.id !== props.sale.id));
async function openMerge() {
    if (await loadPlaces()) {
        mergeInto.value = null;
        mergeOpen.value = true;
    }
}
async function merge() {
    const data = await run(() => axios.post(getRoute("tabs.merge", { tab: props.sale.id }), { into_sale_id: mergeInto.value }), "Could not merge the tabs.");
    if (data) {
        mergeOpen.value = false;
        message.success(`Merged into ${data.data.tab.label}.`);
        emit("changed");
    }
}

// Split: how much of each line moves to a new tab on the same table.
const split = reactive({ open: false, name: "", amounts: {} });
const splitLines = computed(() => props.orders.filter((o) => o.sale_item_id));
function openSplit() {
    split.name = "";
    split.amounts = Object.fromEntries(splitLines.value.map((o) => [o.sale_item_id, 0]));
    split.open = true;
}
const splitTotal = computed(() =>
    splitLines.value.reduce((s, o) => s + toQuantity(split.amounts[o.sale_item_id]) * (parseFloat(o.price) || 0), 0),
);
async function doSplit() {
    const lines = splitLines.value
        .map((o) => ({ sale_item_id: o.sale_item_id, quantity: toQuantity(split.amounts[o.sale_item_id]) }))
        .filter((l) => l.quantity > 0);
    if (!lines.length) {
        message.warning("Pick what goes on the new tab.");
        return;
    }
    const data = await run(() => axios.post(getRoute("tabs.split", { tab: props.sale.id }), { lines, tab_name: split.name || null }), "Could not split the tab.");
    if (data) {
        split.open = false;
        message.success(`${data.data.tab.label} opened with ${formattedTotal(data.data.tab.total)}.`);
        emit("changed");
    }
}

// Rename / guests.
const edit = reactive({ open: false, name: "", guests: null });
function openEdit() {
    edit.name = props.sale.tab_name ?? "";
    edit.guests = props.sale.guests ?? null;
    edit.open = true;
}
async function saveEdit() {
    const data = await run(() => axios.patch(getRoute("tabs.update", { tab: props.sale.id }), { tab_name: edit.name || null, guests: edit.guests || null }), "Could not save.");
    if (data) {
        edit.open = false;
        emit("changed");
    }
}

// Cancel: an empty tab just closes; one with items needs a manager's PIN.
const cancel = reactive({ open: false, pin: "", reason: "" });
const hasItems = computed(() => props.orders.length > 0);
async function doCancel() {
    const data = await run(() => axios.post(getRoute("tabs.cancel", { tab: props.sale.id }), { pin: cancel.pin || null, reason: cancel.reason || null }), "Could not cancel the tab.");
    if (data) {
        cancel.open = false;
        message.success("Tab cancelled.");
        emit("left");
    }
}

async function backToTables() {
    const data = await run(() => axios.post(getRoute("tabs.leave")), "Could not leave the tab.");
    if (data) emit("left");
}

const onMenu = ({ key }) =>
    ({ move: openMove, merge: openMerge, split: openSplit, edit: openEdit, tickets: showTickets, cancel: () => Object.assign(cancel, { open: true, pin: "", reason: "" }) })[key]?.();
</script>

<template>
    <div class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-orange-300 bg-orange-50 px-3 py-2" data-testid="tab-bar">
        <IconArmchair :size="20" class="text-orange-700" />
        <div class="min-w-0 flex-1">
            <div class="truncate font-semibold text-orange-900" data-testid="tab-label">{{ label }}</div>
            <div v-if="sale.guests" class="text-xs text-orange-800">{{ sale.guests }} guest{{ sale.guests > 1 ? "s" : "" }}</div>
        </div>
        <a-button type="primary" size="small" :loading="busy" :disabled="!hasItems" data-testid="tab-send" @click="send">
            <template #icon><IconChefHat :size="16" class="mr-1 inline" /></template>
            Send to kitchen
        </a-button>
        <a-dropdown :trigger="['click']">
            <a-button size="small" data-testid="tab-more">More</a-button>
            <template #overlay>
                <a-menu @click="onMenu">
                    <a-menu-item key="move">Move to another table</a-menu-item>
                    <a-menu-item key="merge">Merge with another tab</a-menu-item>
                    <a-menu-item key="split" :disabled="!hasItems">Split the check</a-menu-item>
                    <a-menu-item key="edit">Name and guests</a-menu-item>
                    <a-menu-item key="tickets">Kitchen tickets</a-menu-item>
                    <a-menu-divider />
                    <a-menu-item key="cancel" danger>Cancel tab</a-menu-item>
                </a-menu>
            </template>
        </a-dropdown>
        <a-button size="small" data-testid="tab-back" @click="backToTables">
            <template #icon><IconLayoutGrid :size="16" class="mr-1 inline" /></template>
            Tables
        </a-button>
    </div>

    <!-- Sent: print it for the kitchen -->
    <a-modal :visible="!!ticket" title="Sent to the kitchen" :width="380" @cancel="ticket = null">
        <div v-if="ticket" class="flex justify-center rounded border bg-white p-2" data-testid="kitchen-ticket">
            <div :id="printId"><KitchenTicket :ticket="ticket" /></div>
        </div>
        <template #footer>
            <a-button @click="ticket = null">Done</a-button>
            <span v-print="{ id: printId, popTitle: ticket ? `Kitchen ${ticket.label}` : 'Kitchen' }">
                <a-button type="primary" data-testid="kitchen-ticket-print">Print</a-button>
            </span>
        </template>
    </a-modal>

    <a-modal :visible="!!tickets" title="Kitchen tickets" :footer="null" :width="420" @cancel="tickets = null">
        <p v-if="tickets && !tickets.length" class="text-gray-500">Nothing sent to the kitchen yet.</p>
        <div v-for="t in tickets || []" :key="t.id" class="mb-3 flex items-start justify-between gap-2 border-b pb-3">
            <div>
                <div class="font-semibold">#{{ t.number }} · {{ t.sent_at }} <a-tag v-if="t.is_addition" color="orange">Addition</a-tag></div>
                <div class="text-xs text-gray-600">{{ t.items.map((i) => `${formatQuantity(i.quantity, i)} ${i.name}`).join(", ") }}</div>
            </div>
            <a-button size="small" @click="ticket = t; tickets = null">Print again</a-button>
        </div>
    </a-modal>

    <a-modal :visible="moveOpen" title="Move to another table" ok-text="Move" :confirm-loading="busy" :width="380" @ok="move" @cancel="moveOpen = false">
        <a-select
            v-model:value="moveTo"
            class="w-full"
            placeholder="Pick a table"
            :options="[
                ...(sale.tab_name ? [{ value: null, label: `No table (keep as ${sale.tab_name})` }] : []),
                ...places.tables.map((t) => ({ value: t.id, label: t.tabs.length && t.id !== sale.dining_table_id ? `${t.name} (occupied)` : t.name })),
            ]"
        />
    </a-modal>

    <a-modal :visible="mergeOpen" title="Merge with another tab" ok-text="Merge" :confirm-loading="busy" :ok-button-props="{ disabled: !mergeInto }" :width="400" @ok="merge" @cancel="mergeOpen = false">
        <p class="text-sm text-gray-600">Everything on {{ label }} moves onto the tab you pick, and {{ label }} closes.</p>
        <p v-if="!otherTabs.length" class="text-gray-500">No other open tab at this store.</p>
        <a-radio-group v-model:value="mergeInto" class="flex flex-col gap-2">
            <a-radio v-for="t in otherTabs" :key="t.id" :value="t.id">{{ t.label }} · {{ formattedTotal(t.total) }}</a-radio>
        </a-radio-group>
    </a-modal>

    <a-modal :visible="split.open" title="Split the check" ok-text="Open new tab" :confirm-loading="busy" :width="460" @ok="doSplit" @cancel="split.open = false">
        <p class="text-sm text-gray-600">Pick what goes on a separate tab at the same table.</p>
        <div class="flex flex-col gap-2">
            <div v-for="o in splitLines" :key="o.sale_item_id" class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ o.name }}</div>
                    <div class="text-xs text-gray-500">{{ formatQuantity(o.quantity, o) }} on the tab · {{ formattedTotal(parseFloat(o.price) || 0) }} each</div>
                </div>
                <a-input-number
                    v-model:value="split.amounts[o.sale_item_id]"
                    :min="0"
                    :max="toQuantity(o.quantity)"
                    :step="quantityStep(o)"
                    :precision="allowsDecimal(o) ? 3 : 0"
                    class="w-24"
                    :aria-label="`How many ${o.name} to move`"
                />
            </div>
        </div>
        <a-input v-model:value="split.name" class="mt-3" placeholder="Name for the new tab (optional)" :maxlength="60" />
        <p class="mt-2 mb-0 text-sm">New tab: <span class="font-semibold">{{ formattedTotal(splitTotal) }}</span></p>
    </a-modal>

    <a-modal :visible="edit.open" title="Name and guests" ok-text="Save" :confirm-loading="busy" :width="360" @ok="saveEdit" @cancel="edit.open = false">
        <div class="flex flex-col gap-3">
            <a-input v-model:value="edit.name" placeholder="Name (e.g. Ana)" :maxlength="60" />
            <a-input-number v-model:value="edit.guests" :min="1" :max="500" placeholder="Guests" class="w-full" />
        </div>
    </a-modal>

    <a-modal :visible="cancel.open" title="Cancel this tab?" ok-text="Cancel tab" :ok-button-props="{ danger: true }" cancel-text="Keep it" :confirm-loading="busy" :width="380" @ok="doCancel" @cancel="cancel.open = false">
        <template v-if="hasItems">
            <p class="text-sm text-gray-600">The items on {{ label }} are voided. A manager's PIN is needed.</p>
            <a-input-password v-model:value="cancel.pin" placeholder="Manager PIN" class="mb-2" autocomplete="off" />
            <a-input v-model:value="cancel.reason" placeholder="Reason (e.g. Guests left)" :maxlength="255" />
        </template>
        <p v-else class="text-sm text-gray-600">{{ label }} has nothing on it. It just closes.</p>
    </a-modal>
</template>
