<script setup>
import { computed, reactive, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { message as antMessage } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/**
 * Add a line by hand:
 *  - in:   cash put into the drawer (float, or a correction)
 *  - out:  cash taken out (the owner, or a correction); the owner can also take from a channel
 *  - move: money moved into or out of a payment channel (GCash, bank); the drawer doesn't change
 */
const props = defineProps({
    /** in | out | move, or null when closed */
    mode: { type: String, default: null },
    channels: { type: Array, default: () => [] },
    /** Other stores an owner withdrawal can go to, instead of the owner keeping it. */
    otherStores: { type: Array, default: () => [] },
    locationId: { type: Number, default: null },
    todayYmd: { type: String, required: true },
});
const emit = defineEmits(["close"]);
const { getRoute } = useDomainRoutes();

const TYPES = {
    in: [
        { value: "cash_sale_topup", label: "Added float", hint: "Change or small bills put into the drawer." },
        { value: "adjustment", label: "Correction", hint: "Fixes a mistake, adding to the drawer." },
    ],
    out: [
        { value: "owner_draw", label: "Owner took cash", hint: "The owner took money for themselves." },
        { value: "adjustment", label: "Correction", hint: "Fixes a mistake, taking from the drawer." },
    ],
};

const form = reactive({ kind: null, amount: null, from: "drawer", channel: null, toStore: null, moveDirection: "in", date: props.todayYmd, notes: "", saving: false });

watch(
    () => props.mode,
    (mode) => {
        if (!mode) return;
        Object.assign(form, {
            kind: mode === "move" ? null : TYPES[mode][0].value,
            amount: null,
            from: "drawer",
            toStore: null,
            channel: props.channels.length === 1 ? props.channels[0].id : null,
            moveDirection: "in",
            date: props.todayYmd,
            notes: "",
            saving: false,
        });
    },
    { immediate: true },
);

const title = computed(() => ({ in: "Cash in", out: "Cash out", move: "Move money to or from a channel" })[props.mode] ?? "");
const channelOptions = computed(() => props.channels.map((c) => ({ value: c.id, label: c.name })));
const typeHint = computed(() => TYPES[props.mode]?.find((t) => t.value === form.kind)?.hint);
const needsChannel = computed(() => props.mode === "move" || (form.kind === "owner_draw" && form.from === "channel"));
const isFuture = (current) => !!current && current.format("YYYY-MM-DD") > props.todayYmd;

function payload() {
    const base = { amount: Number(form.amount), movement_date: form.date, notes: form.notes || null, location_id: props.locationId };
    if (props.mode === "move") {
        return { ...base, kind: form.moveDirection === "in" ? "ewallet_transfer_in" : "ewallet_transfer_out", direction: form.moveDirection, payment_card_type_id: form.channel };
    }
    if (form.kind === "owner_draw") {
        const fromChannel = form.from === "channel";
        return {
            ...base,
            kind: "owner_draw",
            direction: "out",
            draw_source: fromChannel ? "card_type" : "cash_register",
            payment_card_type_id: fromChannel ? form.channel : null,
            // Into another store's drawer, or (no store) the owner keeps it.
            to_location_id: !fromChannel && form.toStore ? form.toStore : undefined,
        };
    }
    return { ...base, kind: form.kind, direction: props.mode, payment_card_type_id: null };
}

function save() {
    if (!(Number(form.amount) >= 0.01)) return antMessage.warning("Enter the amount.");
    if (needsChannel.value && !form.channel) return antMessage.warning("Choose the payment channel.");
    form.saving = true;
    router.post(getRoute("wallet-cash-ledger.store"), payload(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            antMessage.success("Saved.");
            emit("close");
        },
        onError: (errors) => {
            const first = Object.values(errors ?? {})[0];
            antMessage.error((Array.isArray(first) ? first[0] : first) || "Could not save.");
        },
        onFinish: () => (form.saving = false),
    });
}
</script>

<template>
    <a-modal :visible="!!mode" :title="title" ok-text="Save" :confirm-loading="form.saving" destroy-on-close @ok="save" @cancel="emit('close')">
        <a-form v-if="mode" layout="vertical" class="pt-1">
            <a-form-item v-if="mode !== 'move'" label="What happened">
                <a-radio-group v-model:value="form.kind" button-style="solid" data-testid="cash-type">
                    <a-radio-button v-for="t in TYPES[mode]" :key="t.value" :value="t.value">{{ t.label }}</a-radio-button>
                </a-radio-group>
                <div v-if="typeHint" class="mt-1 text-xs text-gray-500">{{ typeHint }}</div>
            </a-form-item>

            <a-form-item v-if="form.kind === 'owner_draw' && form.from === 'drawer' && otherStores.length" label="Where the cash went">
                <a-select
                    v-model:value="form.toStore"
                    allow-clear
                    placeholder="The owner kept it"
                    :options="otherStores.map((s) => ({ value: s.id, label: `Into ${s.name}'s drawer` }))"
                    data-testid="owner-to-store"
                />
                <div class="mt-1 text-xs text-gray-500">Moving it to another store's drawer adds it there too.</div>
            </a-form-item>

            <a-form-item v-if="form.kind === 'owner_draw' && channels.length" label="Taken from">
                <a-radio-group v-model:value="form.from">
                    <a-radio value="drawer">Cash drawer</a-radio>
                    <a-radio value="channel">A payment channel</a-radio>
                </a-radio-group>
            </a-form-item>

            <a-form-item v-if="mode === 'move'" label="Direction">
                <a-radio-group v-model:value="form.moveDirection" button-style="solid" data-testid="move-direction">
                    <a-radio-button value="in">Into the channel</a-radio-button>
                    <a-radio-button value="out">Out of the channel</a-radio-button>
                </a-radio-group>
            </a-form-item>

            <a-form-item v-if="needsChannel" label="Payment channel" required>
                <a-select v-model:value="form.channel" :options="channelOptions" placeholder="GCash, bank, card terminal…" data-testid="cash-channel" />
                <div v-if="mode === 'move'" class="mt-1 text-xs text-gray-500">
                    This changes the channel's balance under Where money is. The cash drawer doesn't change.
                </div>
                <div v-else class="mt-1 text-xs text-gray-500">This comes out of the channel's balance, not the cash drawer.</div>
            </a-form-item>

            <a-form-item label="Amount" required>
                <a-input-number v-model:value="form.amount" :min="0.01" :step="0.01" :precision="2" class="w-full" addon-before="₱" data-testid="cash-amount" />
            </a-form-item>
            <a-form-item label="Date">
                <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" :allow-clear="false" :disabled-date="isFuture" class="w-full" />
            </a-form-item>
            <a-form-item label="Notes (optional)" class="mb-0">
                <a-input v-model:value="form.notes" :maxlength="2000" data-testid="cash-notes" />
            </a-form-item>
        </a-form>
    </a-modal>
</template>
