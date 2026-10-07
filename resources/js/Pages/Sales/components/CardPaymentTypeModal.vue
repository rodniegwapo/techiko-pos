<script setup>
import { ref, watch, computed } from "vue";
import axios from "axios";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const { getRoute } = useDomainRoutes();

function firstValidationMessage(err) {
    const errors = err?.response?.data?.errors;
    if (errors && typeof errors === "object") {
        const first = Object.values(errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return (
        err?.response?.data?.message ||
        err?.message ||
        "Request failed."
    );
}

const props = defineProps({
    visible: { type: Boolean, default: false },
    /** When offline, parent supplies types from last online fetch */
    cachedTypes: { type: Array, default: () => [] },
    useNetwork: { type: Boolean, default: true },
    initialSelectedId: { type: [Number, String], default: null },
    /** Which channels to pick from: card terminals, e-wallets or banks. */
    kind: {
        type: String,
        default: "card",
        validator: (v) => ["card", "ewallet", "bank"].includes(v),
    },
});

/** Wording per kind; the card texts are the ones cashiers already know. */
const COPY = {
    card: {
        title: "Card payment type",
        intro: "Select the terminal or card channel used for this payment.",
        thing: "card type",
        empty: "No card types yet. Add one to continue.",
        newPlaceholder: "Type name",
        addAnother: "Add another type",
        anotherPlaceholder: "New type name",
        selectHint: "Choose how this card payment was processed.",
        confirm: "Use selected type",
    },
    ewallet: {
        title: "Which e-wallet?",
        intro: "Select the e-wallet the customer paid with.",
        thing: "e-wallet",
        empty: "No e-wallets yet. Add one (e.g. GCash, Maya) to continue.",
        newPlaceholder: "e.g. GCash",
        addAnother: "Add another e-wallet",
        anotherPlaceholder: "New e-wallet name",
        selectHint: "Choose the e-wallet the customer paid with.",
        confirm: "Use selected e-wallet",
    },
    bank: {
        title: "Which bank?",
        intro: "Select the bank account the customer paid into.",
        thing: "bank",
        empty: "No banks yet. Add one (e.g. BDO, BPI) to continue.",
        newPlaceholder: "e.g. BDO",
        addAnother: "Add another bank",
        anotherPlaceholder: "New bank name",
        selectHint: "Choose the bank the customer paid into.",
        confirm: "Use selected bank",
    },
};
const copy = computed(() => COPY[props.kind] ?? COPY.card);

/** Channels saved before kinds existed are cards. */
const ofKind = (list) => (list || []).filter((t) => (t.kind || "card") === props.kind);

const emit = defineEmits([
    "update:visible",
    "confirm",
    "cancel",
    "created",
]);

const loading = ref(false);
const types = ref([]);
const selectedId = ref(null);
const newName = ref("");
const adding = ref(false);

const displayTypes = computed(() => types.value);

async function fetchTypes() {
    if (!props.useNetwork) {
        types.value = ofKind(props.cachedTypes);
        return;
    }
    loading.value = true;
    try {
        const { data } = await axios.get(
            getRoute("payment-card-types.list"),
            { params: { kind: props.kind } },
        );
        types.value = ofKind(data?.data ?? []);
    } catch (e) {
        if (ofKind(props.cachedTypes).length) {
            types.value = ofKind(props.cachedTypes);
            notification.warning({
                message: `Using saved ${copy.value.thing} list`,
                description: "Could not refresh the list. Showing the ones from your last online session.",
            });
        } else {
            types.value = [];
            notification.error({
                message: `Could not load the ${copy.value.thing} list`,
                description: firstValidationMessage(e) || "Check your connection.",
            });
        }
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.visible,
    async (v) => {
        if (!v) return;
        selectedId.value = props.initialSelectedId
            ? Number(props.initialSelectedId)
            : null;
        await fetchTypes();
        if (
            selectedId.value &&
            !types.value.some((t) => t.id === selectedId.value)
        ) {
            selectedId.value = null;
        }
    },
);

watch(
    () => props.cachedTypes,
    () => {
        if (!props.useNetwork && props.visible) {
            types.value = ofKind(props.cachedTypes);
        }
    },
    { deep: true },
);

async function addType() {
    const name = String(newName.value || "").trim();
    if (!name) {
        notification.warning({ message: `Enter a name for the ${copy.value.thing}.` });
        return;
    }
    adding.value = true;
    try {
        const { data } = await axios.post(
            getRoute("payment-card-types.store"),
            { name, kind: props.kind },
        );
        const created = data?.data;
        if (created?.id) {
            types.value = [...types.value, created];
            selectedId.value = created.id;
            newName.value = "";
            emit("created", created);
            notification.success({ message: `${copy.value.thing[0].toUpperCase()}${copy.value.thing.slice(1)} added.` });
        }
    } catch (e) {
        notification.error({
            message: firstValidationMessage(e) || `Could not add the ${copy.value.thing}.`,
        });
    } finally {
        adding.value = false;
    }
}

function onConfirm() {
    if (!selectedId.value) {
        notification.warning({
            message: `Select a ${copy.value.thing}`,
            description: copy.value.selectHint,
        });
        return;
    }
    // The channel itself too, so the parent can show its name (it may have just been added here).
    emit(
        "confirm",
        selectedId.value,
        types.value.find((t) => t.id === selectedId.value) ?? null,
    );
    emit("update:visible", false);
}

function onCancel() {
    emit("cancel");
    emit("update:visible", false);
}
</script>

<template>
    <a-modal
        :visible="visible"
        :title="copy.title"
        :confirm-loading="false"
        width="480px"
        :mask-closable="false"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <div class="py-2 space-y-4">
            <p class="text-sm text-gray-600">
                {{ copy.intro }}
            </p>

            <a-spin :spinning="loading">
                <div
                    v-if="!loading && displayTypes.length === 0"
                    class="rounded border border-dashed border-gray-300 p-4 space-y-3"
                >
                    <p class="text-sm text-gray-700 m-0">
                        {{ copy.empty }}
                    </p>
                    <div class="flex gap-2">
                        <a-input
                            v-model:value="newName"
                            :placeholder="copy.newPlaceholder"
                            :disabled="!useNetwork"
                            @press-enter="addType"
                        />
                        <a-button
                            type="primary"
                            class="bg-green-700 border-green-700 hover:bg-green-600"
                            :loading="adding"
                            :disabled="!useNetwork"
                            @click="addType"
                        >
                            Add
                        </a-button>
                    </div>
                    <p v-if="!useNetwork" class="text-xs text-amber-700 m-0">
                        Connect to the internet to add a new {{ copy.thing }}, or
                        use one you added while online (saved list).
                    </p>
                </div>

                <a-radio-group
                    v-else
                    v-model:value="selectedId"
                    class="w-full flex flex-col gap-2"
                >
                    <a-radio
                        v-for="t in displayTypes"
                        :key="t.id"
                        :value="t.id"
                        class="!flex !items-start py-1"
                    >
                        {{ t.name }}
                    </a-radio>
                </a-radio-group>

                <div
                    v-if="displayTypes.length > 0 && useNetwork"
                    class="mt-4 pt-3 border-t border-gray-100"
                >
                    <p class="text-xs text-gray-500 mb-2">{{ copy.addAnother }}</p>
                    <div class="flex gap-2">
                        <a-input
                            v-model:value="newName"
                            :placeholder="copy.anotherPlaceholder"
                            :disabled="adding"
                            @press-enter="addType"
                        />
                        <a-button
                            type="primary"
                            class="bg-green-700 border-green-700 hover:bg-green-600"
                            :loading="adding"
                            @click="addType"
                        >
                            Add
                        </a-button>
                    </div>
                </div>
            </a-spin>
        </div>

        <template #footer>
            <a-button @click="onCancel">Pay with cash instead</a-button>
            <a-button
                type="primary"
                class="bg-green-700 border-green-700 hover:bg-green-600"
                :disabled="!selectedId"
                @click="onConfirm"
            >
                {{ copy.confirm }}
            </a-button>
        </template>
    </a-modal>
</template>
