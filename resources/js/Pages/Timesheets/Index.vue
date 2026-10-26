<script setup>
import { computed, reactive, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

/**
 * Timesheets: who worked when, per person per day, with the hours to pay. Staff clock in and out
 * on the POS with their own PIN; here a manager fixes a forgotten clock-out or adds a missed
 * shift. Every change asks for a reason and is kept.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    people: { type: Array, default: () => [] },
    totalMinutes: { type: Number, default: 0 },
    onTheClock: { type: Array, default: () => [] },
    forgotten: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    canPickAllStores: { type: Boolean, default: false },
    staff: { type: Array, default: () => [] },
    staleAfterHours: { type: Number, default: 16 },
});

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();

const range = ref([props.filters.from, props.filters.to]);
const locationId = ref(props.filters.location_id);

const query = computed(() => ({
    from: range.value?.[0] ?? undefined,
    to: range.value?.[1] ?? undefined,
    location_id: locationId.value ?? undefined,
}));

function reload() {
    router.get(getRoute("timesheets.index"), query.value, { preserveState: true, preserveScroll: true, replace: true });
}

const exportUrl = computed(() => {
    const params = new URLSearchParams(Object.entries(query.value).filter(([, v]) => v != null && v !== ""));
    return `${getRoute("timesheets.export")}?${params}`;
});

const hours = (minutes) => {
    const m = Math.max(0, Math.round(minutes || 0));
    return `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, "0")}m`;
};
const time = (value) => (value ? dayjs(value).format("h:mm A") : "");
const day = (value) => dayjs(value).format("ddd, MMM D");
const since = (value) => dayjs(value).format("MMM D, h:mm A");

// Add or fix a shift.
const modal = reactive({ open: false, entry: null });
const form = reactive({ user_id: null, location_id: null, clock_in_at: null, clock_out_at: null, reason: "", notes: "" });
const errors = ref({});
const saving = ref(false);

function openAdd() {
    Object.assign(form, {
        user_id: null,
        location_id: props.filters.location_id ?? props.locations[0]?.id ?? null,
        clock_in_at: null,
        clock_out_at: null,
        reason: "",
        notes: "",
    });
    errors.value = {};
    modal.entry = null;
    modal.open = true;
}

function openEdit(entry) {
    Object.assign(form, {
        user_id: entry.user_id,
        location_id: entry.location_id,
        clock_in_at: entry.clock_in_at,
        clock_out_at: entry.clock_out_at,
        reason: entry.forgotten ? "Forgot to clock out" : "",
        notes: entry.notes ?? "",
    });
    errors.value = {};
    modal.entry = entry;
    modal.open = true;
}

function save() {
    saving.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => (modal.open = false),
        onError: (e) => (errors.value = e),
        onFinish: () => (saving.value = false),
    };
    const payload = {
        clock_in_at: form.clock_in_at,
        clock_out_at: form.clock_out_at || null,
        reason: form.reason,
        notes: form.notes || null,
    };
    if (modal.entry) {
        router.put(getRoute("timesheets.update", { timeEntry: modal.entry.id }), payload, options);
    } else {
        router.post(getRoute("timesheets.store"), { ...payload, user_id: form.user_id, location_id: form.location_id }, options);
    }
}

const modalMinutes = computed(() => {
    if (!form.clock_in_at || !form.clock_out_at) return null;
    return dayjs(form.clock_out_at).diff(dayjs(form.clock_in_at), "minute");
});
const canEdit = computed(() => hasPermission("timesheets.update"));
</script>

<template>
    <Head title="Timesheets" />
    <AuthenticatedLayout>
        <ContentHeader class="mb-4" title="Timesheets" />

        <div class="mb-4 flex flex-col gap-3">
            <a-alert v-if="forgotten.length" type="warning" show-icon data-testid="timesheets-forgotten">
                <template #message>
                    {{ forgotten.length === 1 ? "1 person" : `${forgotten.length} people` }} never clocked out
                </template>
                <template #description>
                    <p class="mb-2 text-xs text-gray-600">
                        Still open after {{ staleAfterHours }} hours. Put in the time they left so their hours count.
                    </p>
                    <div class="flex flex-col gap-1">
                        <div v-for="e in forgotten" :key="e.id" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium">{{ e.user }}</span>
                            <span class="text-gray-600">clocked in {{ since(e.clock_in_at) }} at {{ e.location }}</span>
                            <a-button v-if="canEdit" size="small" type="primary" ghost @click="openEdit(e)">Close it</a-button>
                        </div>
                    </div>
                </template>
            </a-alert>

            <div v-if="onTheClock.length" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3" data-testid="timesheets-on-the-clock">
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-emerald-800">On the clock now</div>
                <div class="flex flex-wrap gap-2">
                    <a-tag v-for="e in onTheClock" :key="e.id" color="green">
                        {{ e.user }} · since {{ time(e.clock_in_at) }}<template v-if="canPickAllStores"> · {{ e.location }}</template>
                    </a-tag>
                </div>
            </div>
        </div>

        <ContentLayout title="Hours worked">
            <template #filters>
                <div class="flex w-full flex-wrap items-center justify-end gap-2">
                    <a-range-picker
                        v-model:value="range"
                        value-format="YYYY-MM-DD"
                        :allow-clear="false"
                        class="w-full md:w-auto"
                        data-testid="timesheets-range"
                        @change="reload"
                    />
                    <a-select
                        v-model:value="locationId"
                        :allow-clear="canPickAllStores"
                        :disabled="!canPickAllStores"
                        placeholder="All stores"
                        class="w-full md:w-48"
                        :options="locations.map((l) => ({ value: l.id, label: l.name }))"
                        @change="reload"
                    />
                    <a-button v-if="hasPermission('timesheets.export')" :href="exportUrl">Export CSV</a-button>
                    <a-button v-if="hasPermission('timesheets.store')" type="primary" data-testid="timesheets-add" @click="openAdd">
                        Add a shift
                    </a-button>
                </div>
            </template>
            <template #table>
                <div v-if="!people.length" class="py-10 text-center text-gray-500">
                    No one clocked in between these dates.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm" data-testid="timesheets-table">
                        <thead>
                            <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-2 pr-3 font-medium">Person</th>
                                <th class="py-2 pr-3 font-medium">Day</th>
                                <th class="py-2 pr-3 font-medium">In – out</th>
                                <th class="py-2 text-right font-medium">Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="person in people" :key="person.user_id">
                                <tr v-for="(d, i) in person.days" :key="d.date" class="border-b border-gray-100 align-top">
                                    <td class="py-2 pr-3 font-medium text-gray-900">{{ i === 0 ? person.name : "" }}</td>
                                    <td class="py-2 pr-3 text-gray-700">{{ day(d.date) }}</td>
                                    <td class="py-2 pr-3">
                                        <div v-for="e in d.entries" :key="e.id" class="flex flex-wrap items-center gap-2">
                                            <span>{{ time(e.clock_in_at) }} – {{ e.open ? "" : time(e.clock_out_at) }}</span>
                                            <a-tag v-if="e.forgotten" color="orange" class="m-0">Forgot to clock out</a-tag>
                                            <a-tag v-else-if="e.open" color="green" class="m-0">On the clock</a-tag>
                                            <a-tag v-if="e.edited" class="m-0">Edited</a-tag>
                                            <span v-if="canPickAllStores" class="text-xs text-gray-500">{{ e.location }}</span>
                                            <a-button v-if="canEdit" type="link" size="small" class="px-0" @click="openEdit(e)">Fix</a-button>
                                        </div>
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ hours(d.minutes) }}</td>
                                </tr>
                                <tr class="border-b bg-gray-50">
                                    <td colspan="3" class="py-2 pr-3 text-right text-xs font-semibold uppercase text-gray-500">
                                        {{ person.name }} total
                                    </td>
                                    <td class="py-2 text-right font-semibold tabular-nums" data-testid="timesheets-person-total">{{ hours(person.minutes) }}</td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="pt-3 text-right font-semibold">Everyone</td>
                                <td class="pt-3 text-right font-bold tabular-nums">{{ hours(totalMinutes) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="modal.open"
            :title="modal.entry ? `Fix ${modal.entry.user}'s shift` : 'Add a shift'"
            ok-text="Save"
            :confirm-loading="saving"
            destroy-on-close
            @ok="save"
        >
            <a-form layout="vertical" class="pt-1">
                <template v-if="!modal.entry">
                    <a-form-item label="Person" :validate-status="errors.user_id ? 'error' : ''" :help="errors.user_id">
                        <a-select
                            v-model:value="form.user_id"
                            show-search
                            option-filter-prop="label"
                            placeholder="Who worked"
                            :options="staff.map((s) => ({ value: s.id, label: s.name }))"
                        />
                    </a-form-item>
                    <a-form-item label="Store" :validate-status="errors.location_id ? 'error' : ''" :help="errors.location_id">
                        <a-select v-model:value="form.location_id" :options="locations.map((l) => ({ value: l.id, label: l.name }))" />
                    </a-form-item>
                </template>
                <div class="grid grid-cols-1 gap-x-3 md:grid-cols-2">
                    <a-form-item label="Clocked in" :validate-status="errors.clock_in_at ? 'error' : ''" :help="errors.clock_in_at">
                        <a-date-picker
                            v-model:value="form.clock_in_at"
                            show-time
                            format="MMM D, h:mm A"
                            value-format="YYYY-MM-DD HH:mm"
                            class="w-full"
                        />
                    </a-form-item>
                    <a-form-item label="Clocked out" :validate-status="errors.clock_out_at ? 'error' : ''" :help="errors.clock_out_at">
                        <a-date-picker
                            v-model:value="form.clock_out_at"
                            show-time
                            format="MMM D, h:mm A"
                            value-format="YYYY-MM-DD HH:mm"
                            class="w-full"
                            placeholder="Still working"
                        />
                    </a-form-item>
                </div>
                <p v-if="modalMinutes !== null" class="-mt-2 mb-3 text-sm text-gray-600">
                    That is <span class="font-semibold text-gray-900">{{ hours(modalMinutes) }}</span>.
                </p>
                <a-form-item label="Why the change" required :validate-status="errors.reason ? 'error' : ''" :help="errors.reason">
                    <a-input v-model:value="form.reason" placeholder="e.g. Forgot to clock out, the till was down" :maxlength="255" />
                </a-form-item>
                <a-form-item label="Notes" class="mb-0">
                    <a-textarea v-model:value="form.notes" :rows="2" :maxlength="500" />
                </a-form-item>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
