<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import axios from "axios";
import { message } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * The tables screen on the POS: the store's floor plan, free tables in green and occupied ones in
 * orange with their total and how long they have been open, plus tabs kept under a name. Tapping
 * a free table opens a tab on it; tapping an occupied one picks its tab up. "New tab" opens one
 * under a name (counter or take-away guests).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(["update:open", "picked"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const loading = ref(false);
const busy = ref(false);
const state = ref({ tables: [], tabs: [], active_tab_id: null });

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(getRoute("tabs.index"));
        state.value = data;
    } catch (e) {
        message.error(e?.response?.data?.errors?.location_id?.[0] ?? "Could not load the tables.");
    } finally {
        loading.value = false;
    }
}

// Kept fresh while it is on screen: other tills open and pay tabs too.
let timer = null;
watch(
    () => props.open,
    (open) => {
        clearInterval(timer);
        if (open) {
            load();
            timer = setInterval(load, 15000);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(timer));

const grid = computed(() => {
    const tables = state.value.tables;
    const cols = Math.max(4, ...tables.map((t) => t.pos_x + 1));
    const rows = Math.max(1, ...tables.map((t) => t.pos_y + 1));
    return { cols, rows };
});
const nameTabs = computed(() => state.value.tabs.filter((t) => !t.dining_table_id));

function close() {
    emit("update:open", false);
}

async function pick(tabId) {
    busy.value = true;
    try {
        await axios.post(getRoute("tabs.select", { tab: tabId }));
        emit("picked", tabId);
        close();
    } catch (e) {
        message.error(e?.response?.data?.errors?.tab?.[0] ?? "Could not open that tab.");
        load();
    } finally {
        busy.value = false;
    }
}

// A table with more than one tab (a split check) asks which one.
const choosing = ref(null);

// A table waiting to be cleared after its guests paid: mark it ready, and maybe seat the next guests.
const clearing = ref(null);

async function markReady(table, seat) {
    busy.value = true;
    try {
        await axios.post(getRoute("tabs.ready", { diningTable: table.id }));
        clearing.value = null;
        await load();
        if (seat) onTable({ ...table, status: "free", tabs: [] });
    } catch (e) {
        message.error(e?.response?.data?.errors?.location_id?.[0] ?? "Could not mark the table ready.");
    } finally {
        busy.value = false;
    }
}

function onTable(table) {
    if (busy.value) return;
    if (table.status === "cleaning") {
        clearing.value = table;
        return;
    }
    if (!table.tabs.length) {
        newTab.table = table;
        newTab.name = "";
        newTab.guests = null;
        newTab.open = true;
        return;
    }
    if (table.tabs.length === 1) {
        pick(table.tabs[0].id);
        return;
    }
    choosing.value = table;
}

const newTab = reactive({ open: false, table: null, name: "", guests: null, error: "" });

function openNamed() {
    newTab.table = null;
    newTab.name = "";
    newTab.guests = null;
    newTab.error = "";
    newTab.open = true;
}

async function createTab() {
    busy.value = true;
    newTab.error = "";
    try {
        const { data } = await axios.post(getRoute("tabs.store"), {
            dining_table_id: newTab.table?.id ?? null,
            tab_name: newTab.name || null,
            guests: newTab.guests || null,
        });
        newTab.open = false;
        emit("picked", data.tab.id);
        close();
    } catch (e) {
        const errors = e?.response?.data?.errors ?? {};
        newTab.error = Object.values(errors)[0]?.[0] ?? "Could not open the tab.";
    } finally {
        busy.value = false;
    }
}

const since = (minutes) => (minutes >= 60 ? `${Math.floor(minutes / 60)}h ${minutes % 60}m` : `${minutes}m`);
</script>

<template>
    <a-modal
        :visible="open"
        title="Tables"
        :footer="null"
        width="min(1100px, 96vw)"
        :body-style="{ padding: '16px' }"
        @cancel="close"
    >
        <div class="flex flex-col gap-4" data-testid="tables-screen">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-3 text-xs text-gray-600">
                        <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-emerald-500" /> Free</span>
                        <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-orange-500" /> Occupied</span>
                        <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-gray-400" /> Needs clearing</span>
                    </div>
                    <p class="mb-0 text-xs text-gray-500" data-testid="tables-hint">
                        Tap a green table to seat guests, an orange one to add to their order, a grey one once it's cleared.
                    </p>
                </div>
                <div class="flex gap-2">
                    <a-button :loading="loading" @click="load">Refresh</a-button>
                    <a-button type="primary" data-testid="tab-new-named" @click="openNamed">New tab (name)</a-button>
                </div>
            </div>

            <a-spin :spinning="loading && !state.tables.length">
                <div v-if="!state.tables.length && !loading" class="rounded-lg border border-dashed p-8 text-center text-gray-500">
                    No tables yet. A manager adds them under Tables. You can still open a tab under a name.
                </div>
                <div
                    v-else
                    class="grid gap-2 overflow-x-auto"
                    :style="{
                        gridTemplateColumns: `repeat(${grid.cols}, minmax(84px, 1fr))`,
                        gridTemplateRows: `repeat(${grid.rows}, minmax(84px, auto))`,
                    }"
                >
                    <button
                        v-for="t in state.tables"
                        :key="t.id"
                        type="button"
                        class="flex flex-col items-center justify-center rounded-xl border-2 p-2 text-center transition hover:shadow-md disabled:opacity-60"
                        :class="t.tabs.length
                            ? 'border-orange-400 bg-orange-50 text-orange-900'
                            : t.status === 'cleaning'
                              ? 'border-dashed border-gray-400 bg-gray-100 text-gray-600'
                              : 'border-emerald-400 bg-emerald-50 text-emerald-900'"
                        :style="{ gridColumnStart: t.pos_x + 1, gridRowStart: t.pos_y + 1 }"
                        :disabled="busy"
                        :data-testid="`table-${t.name}`"
                        @click="onTable(t)"
                    >
                        <span class="text-lg font-bold leading-tight">{{ t.name }}</span>
                        <span v-if="t.area" class="text-[10px] uppercase tracking-wide opacity-70">{{ t.area }}</span>
                        <template v-if="t.tabs.length">
                            <span class="mt-1 text-sm font-semibold">{{ formattedTotal(t.tabs.reduce((s, x) => s + x.total, 0)) }}</span>
                            <span class="text-[11px]">
                                {{ since(Math.max(...t.tabs.map((x) => x.minutes_open))) }}
                                <template v-if="t.tabs.length > 1"> · {{ t.tabs.length }} tabs</template>
                            </span>
                            <span v-if="t.tabs.some((x) => x.unsent)" class="mt-0.5 rounded bg-orange-200 px-1 text-[10px] font-semibold">Not sent</span>
                        </template>
                        <template v-else-if="t.status === 'cleaning'">
                            <span class="mt-1 text-[11px] font-semibold" :data-testid="`table-cleaning-${t.name}`">Needs clearing</span>
                            <span class="text-[10px]">paid {{ since(t.minutes_since_paid ?? 0) }} ago</span>
                        </template>
                        <span v-else class="mt-1 text-[11px]">{{ t.seats }} seats</span>
                    </button>
                </div>
            </a-spin>

            <div v-if="nameTabs.length">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Tabs by name</div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="tab in nameTabs"
                        :key="tab.id"
                        type="button"
                        class="rounded-lg border-2 border-orange-400 bg-orange-50 px-3 py-2 text-left hover:shadow-md"
                        :data-testid="`tab-named-${tab.tab_name}`"
                        @click="pick(tab.id)"
                    >
                        <div class="font-semibold text-orange-900">{{ tab.label }}</div>
                        <div class="text-xs text-orange-800">{{ formattedTotal(tab.total) }} · {{ since(tab.minutes_open) }}</div>
                    </button>
                </div>
            </div>
        </div>

        <a-modal
            :visible="!!clearing"
            :title="clearing ? `Is ${clearing.name} ready?` : ''"
            :footer="null"
            :width="360"
            @cancel="clearing = null"
        >
            <div v-if="clearing" class="flex flex-col gap-2">
                <p class="mb-1 text-sm text-gray-600">
                    Its guests paid {{ since(clearing.minutes_since_paid ?? 0) }} ago. Once the table is cleared, it's free for the next guests.
                </p>
                <a-button block size="large" type="primary" :loading="busy" data-testid="table-ready-seat" @click="markReady(clearing, true)">
                    Cleared, seat new guests
                </a-button>
                <a-button block :disabled="busy" data-testid="table-ready" @click="markReady(clearing, false)">
                    Cleared, keep it free
                </a-button>
            </div>
        </a-modal>

        <a-modal
            :visible="!!choosing"
            :title="choosing ? `Which tab at ${choosing.name}?` : ''"
            :footer="null"
            :width="360"
            @cancel="choosing = null"
        >
            <div v-if="choosing" class="flex flex-col gap-2">
                <a-button
                    v-for="tab in choosing.tabs"
                    :key="tab.id"
                    block
                    size="large"
                    @click="pick(tab.id); choosing = null"
                >
                    {{ tab.label }} · {{ formattedTotal(tab.total) }}
                </a-button>
                <a-button block type="dashed" @click="newTab.table = choosing; newTab.name = ''; newTab.guests = null; newTab.open = true; choosing = null">
                    Another tab at {{ choosing.name }}
                </a-button>
            </div>
        </a-modal>

        <a-modal
            :visible="newTab.open"
            :title="newTab.table ? `Open ${newTab.table.name}` : 'New tab'"
            ok-text="Open tab"
            :confirm-loading="busy"
            :width="380"
            :ok-button-props="{ 'data-testid': 'tab-open-confirm' }"
            @ok="createTab"
            @cancel="newTab.open = false"
        >
            <div class="flex flex-col gap-3">
                <a-input
                    v-model:value="newTab.name"
                    :placeholder="newTab.table ? 'Name (optional), e.g. Ana' : 'Name, e.g. Ana or Grab #123'"
                    :maxlength="60"
                    data-testid="tab-name"
                    @press-enter="createTab"
                />
                <a-input-number
                    v-model:value="newTab.guests"
                    :min="1"
                    :max="500"
                    placeholder="Guests (optional)"
                    class="w-full"
                    data-testid="tab-guests"
                />
                <p v-if="newTab.error" class="m-0 text-sm text-red-600">{{ newTab.error }}</p>
            </div>
        </a-modal>
    </a-modal>
</template>
