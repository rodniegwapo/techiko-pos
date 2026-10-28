<script setup>
import { computed, reactive, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/**
 * Tables setup: turn dine-in on for a store, add its tables and drag them onto a floor plan. The
 * POS tables screen shows them the same way, free or occupied.
 */
const props = defineProps({
    stores: { type: Array, default: () => [] },
    location: { type: Object, default: null },
    tables: { type: Array, default: () => [] },
    grid: { type: Object, default: () => ({ columns: 12, rows: 8 }) },
});

const { getRoute } = useDomainRoutes();
const opts = { preserveScroll: true };

const storeId = ref(props.location?.id ?? null);
function pickStore() {
    router.get(getRoute("dining-tables.index"), { location_id: storeId.value }, { preserveState: false });
}

const togglingDineIn = ref(false);
function setDineIn(on) {
    togglingDineIn.value = true;
    router.put(getRoute("dining-tables.settings"), { location_id: props.location.id, dine_in: on }, {
        ...opts,
        onFinish: () => (togglingDineIn.value = false),
    });
}

// Add or edit a table.
const form = reactive({ open: false, id: null, name: "", area: "", seats: 4, is_active: true });
const errors = ref({});
function openForm(table = null) {
    Object.assign(form, table
        ? { open: true, id: table.id, name: table.name, area: table.area ?? "", seats: table.seats, is_active: table.is_active }
        : { open: true, id: null, name: "", area: "", seats: 4, is_active: true });
    errors.value = {};
}
function saveForm() {
    const payload = { name: form.name, area: form.area || null, seats: form.seats, is_active: form.is_active };
    const done = { ...opts, onSuccess: () => (form.open = false), onError: (e) => (errors.value = e) };
    if (form.id) {
        router.put(getRoute("dining-tables.update", { diningTable: form.id }), payload, done);
    } else {
        router.post(getRoute("dining-tables.store"), { ...payload, location_id: props.location.id }, done);
    }
}
function remove(table) {
    router.delete(getRoute("dining-tables.destroy", { diningTable: table.id }), opts);
}

// The floor plan: drag a table onto another cell, then save.
// Adding, editing or removing a table reloads the list; tables moved but not saved yet keep their
// new cells, and a new table placed on a cell one of them now takes goes to the first free cell.
const positions = ref({});
let saved = {};
watch(
    () => props.tables,
    (tables) => {
        const moved = Object.fromEntries(
            Object.entries(positions.value).filter(([id, p]) => saved[id] && (saved[id].x !== p.x || saved[id].y !== p.y)),
        );
        saved = Object.fromEntries(tables.map((t) => [t.id, { x: t.pos_x, y: t.pos_y }]));
        const next = {};
        for (const t of tables) {
            if (moved[t.id]) next[t.id] = moved[t.id];
        }
        const taken = (x, y) => Object.values(next).some((p) => p.x === x && p.y === y);
        for (const t of tables) {
            if (next[t.id]) continue;
            let spot = { x: t.pos_x, y: t.pos_y };
            if (taken(spot.x, spot.y)) {
                spot = freeCell(taken) ?? spot;
            }
            next[t.id] = spot;
        }
        positions.value = next;
    },
    { immediate: true },
);
function freeCell(taken) {
    for (let y = 0; y < props.grid.rows; y++) {
        for (let x = 0; x < props.grid.columns; x++) {
            if (!taken(x, y)) return { x, y };
        }
    }
    return null;
}
const dirty = computed(() => props.tables.some((t) => positions.value[t.id]?.x !== t.pos_x || positions.value[t.id]?.y !== t.pos_y));
const cells = computed(() => {
    const list = [];
    for (let y = 0; y < props.grid.rows; y++) for (let x = 0; x < props.grid.columns; x++) list.push({ x, y });
    return list;
});
const tableAt = (x, y) => props.tables.find((t) => positions.value[t.id]?.x === x && positions.value[t.id]?.y === y);

const dragging = ref(null);
function drop(x, y) {
    const id = dragging.value;
    dragging.value = null;
    if (!id) return;
    const other = tableAt(x, y);
    const from = { ...positions.value[id] };
    positions.value[id] = { x, y };
    // Dropped on another table: they swap places.
    if (other && other.id !== id) positions.value[other.id] = from;
}
function saveLayout() {
    router.put(getRoute("dining-tables.layout"), {
        location_id: props.location.id,
        positions: props.tables.map((t) => ({ id: t.id, pos_x: positions.value[t.id].x, pos_y: positions.value[t.id].y })),
    }, opts);
}
</script>

<template>
    <Head title="Tables" />
    <AuthenticatedLayout>
        <ContentHeader class="mb-4" title="Tables" />

        <div v-if="!location" class="rounded-lg border border-dashed bg-white p-8 text-center text-gray-500">
            No store to set up. Tables belong to a store (not a warehouse).
        </div>

        <template v-else>
            <div class="mb-4 flex flex-wrap items-center gap-4 rounded-lg bg-white p-4 shadow-sm">
                <a-select
                    v-if="stores.length > 1"
                    v-model:value="storeId"
                    class="w-56"
                    :options="stores.map((s) => ({ value: s.id, label: s.name }))"
                    @change="pickStore"
                />
                <div class="flex items-center gap-3">
                    <a-switch
                        :checked="location.dine_in"
                        :loading="togglingDineIn"
                        data-testid="dine-in-switch"
                        @change="setDineIn"
                    />
                    <div>
                        <div class="font-medium">{{ location.name }} serves dine-in</div>
                        <div class="text-xs text-gray-500">
                            When on, the Sales page there gets a Tables button: open tabs by table or name and send orders to the kitchen.
                        </div>
                    </div>
                </div>
            </div>

            <ContentLayout title="Tables">
                <template #filters>
                    <div class="flex w-full flex-wrap items-center justify-end gap-2">
                        <a-button type="primary" data-testid="table-add" @click="openForm()">Add a table</a-button>
                    </div>
                </template>
                <template #table>
                    <div v-if="!tables.length" class="py-8 text-center text-gray-500">No tables yet. Add the first one.</div>
                    <div v-else class="flex flex-col gap-6">
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <span class="text-sm text-gray-600">Floor plan: drag a table to where it stands. Dropping it on another swaps them.</span>
                                <a-button type="primary" :disabled="!dirty" data-testid="layout-save" @click="saveLayout">Save floor plan</a-button>
                            </div>
                            <div class="overflow-x-auto">
                                <div
                                    class="grid min-w-[720px] gap-1 rounded-lg bg-gray-100 p-2"
                                    :style="{ gridTemplateColumns: `repeat(${grid.columns}, minmax(56px, 1fr))` }"
                                >
                                    <div
                                        v-for="c in cells"
                                        :key="`${c.x}-${c.y}`"
                                        :data-testid="`floor-cell-${c.x}-${c.y}`"
                                        class="flex h-16 items-center justify-center rounded border border-dashed border-gray-300 bg-white/50"
                                        @dragover.prevent
                                        @drop.prevent="drop(c.x, c.y)"
                                    >
                                        <div
                                            v-if="tableAt(c.x, c.y)"
                                            draggable="true"
                                            class="flex h-full w-full cursor-move flex-col items-center justify-center rounded border-2 text-center text-xs"
                                            :class="tableAt(c.x, c.y).is_active ? 'border-emerald-500 bg-emerald-50 text-emerald-900' : 'border-gray-300 bg-gray-50 text-gray-400'"
                                            @dragstart="dragging = tableAt(c.x, c.y).id"
                                            @dragend="dragging = null"
                                        >
                                            <span class="text-sm font-bold">{{ tableAt(c.x, c.y).name }}</span>
                                            <span>{{ tableAt(c.x, c.y).seats }} seats</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <a-table
                            :data-source="tables"
                            row-key="id"
                            size="small"
                            :pagination="false"
                            :columns="[
                                { title: 'Table', key: 'name', dataIndex: 'name' },
                                { title: 'Area', key: 'area', dataIndex: 'area' },
                                { title: 'Seats', key: 'seats', dataIndex: 'seats', align: 'right' },
                                { title: '', key: 'actions', align: 'right' },
                            ]"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'name'">
                                    <span class="font-medium">{{ record.name }}</span>
                                    <a-tag v-if="record.busy" color="orange" class="ml-2">Open tab</a-tag>
                                    <a-tag v-if="!record.is_active" class="ml-2">Hidden</a-tag>
                                </template>
                                <template v-else-if="column.key === 'area'">{{ record.area || "—" }}</template>
                                <template v-else-if="column.key === 'actions'">
                                    <a-button type="link" size="small" @click="openForm(record)">Edit</a-button>
                                    <a-popconfirm
                                        :title="`Remove ${record.name}?`"
                                        ok-text="Remove"
                                        :disabled="record.busy"
                                        @confirm="remove(record)"
                                    >
                                        <a-button type="link" size="small" danger :disabled="record.busy">Remove</a-button>
                                    </a-popconfirm>
                                </template>
                            </template>
                        </a-table>
                    </div>
                </template>
            </ContentLayout>
        </template>

        <a-modal
            v-model:visible="form.open"
            :title="form.id ? `Edit ${form.name}` : 'Add a table'"
            ok-text="Save"
            :width="400"
            destroy-on-close
            @ok="saveForm"
        >
            <a-form layout="vertical" class="pt-1">
                <a-form-item label="Name" required :validate-status="errors.name ? 'error' : ''" :help="errors.name">
                    <a-input v-model:value="form.name" placeholder="e.g. T1, Booth 2" :maxlength="30" data-testid="table-name" />
                </a-form-item>
                <a-form-item label="Area" :validate-status="errors.area ? 'error' : ''" :help="errors.area">
                    <a-input v-model:value="form.area" placeholder="e.g. Patio, 2nd floor (optional)" :maxlength="50" />
                </a-form-item>
                <a-form-item label="Seats" :validate-status="errors.seats ? 'error' : ''" :help="errors.seats">
                    <a-input-number v-model:value="form.seats" :min="1" :max="99" class="w-full" />
                </a-form-item>
                <a-form-item v-if="form.id" class="mb-0" :validate-status="errors.is_active ? 'error' : ''" :help="errors.is_active">
                    <a-checkbox v-model:checked="form.is_active">Show on the POS</a-checkbox>
                </a-form-item>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
