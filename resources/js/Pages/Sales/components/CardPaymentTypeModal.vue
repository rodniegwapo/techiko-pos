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
});

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
        types.value = [...(props.cachedTypes || [])];
        return;
    }
    loading.value = true;
    try {
        const { data } = await axios.get(
            getRoute("payment-card-types.list"),
        );
        types.value = data?.data ?? [];
    } catch (e) {
        if ((props.cachedTypes || []).length) {
            types.value = [...props.cachedTypes];
            notification.warning({
                message: "Using cached card types",
                description: "Could not refresh the list. Showing types from your last online session.",
            });
        } else {
            types.value = [];
            notification.error({
                message: "Could not load card types",
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
            types.value = [...(props.cachedTypes || [])];
        }
    },
    { deep: true },
);

async function addType() {
    const name = String(newName.value || "").trim();
    if (!name) {
        notification.warning({ message: "Enter a name for the card type." });
        return;
    }
    adding.value = true;
    try {
        const { data } = await axios.post(
            getRoute("payment-card-types.store"),
            { name },
        );
        const created = data?.data;
        if (created?.id) {
            types.value = [...types.value, created];
            selectedId.value = created.id;
            newName.value = "";
            emit("created", created);
            notification.success({ message: "Card type added." });
        }
    } catch (e) {
        notification.error({
            message: firstValidationMessage(e) || "Could not add card type.",
        });
    } finally {
        adding.value = false;
    }
}

function onConfirm() {
    if (!selectedId.value) {
        notification.warning({
            message: "Select a card type",
            description: "Choose how this card payment was processed.",
        });
        return;
    }
    emit("confirm", selectedId.value);
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
        title="Card payment type"
        :confirm-loading="false"
        width="480px"
        :mask-closable="false"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <div class="py-2 space-y-4">
            <p class="text-sm text-gray-600">
                Select the terminal or card channel used for this payment.
            </p>

            <a-spin :spinning="loading">
                <div
                    v-if="!loading && displayTypes.length === 0"
                    class="rounded border border-dashed border-gray-300 p-4 space-y-3"
                >
                    <p class="text-sm text-gray-700 m-0">
                        No card types yet. Add one to continue.
                    </p>
                    <div class="flex gap-2">
                        <a-input
                            v-model:value="newName"
                            placeholder="Type name"
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
                        Connect to the internet to create a new card type, or
                        use a type you created while online (cached list).
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
                    <p class="text-xs text-gray-500 mb-2">Add another type</p>
                    <div class="flex gap-2">
                        <a-input
                            v-model:value="newName"
                            placeholder="New type name"
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
                Use selected type
            </a-button>
        </template>
    </a-modal>
</template>
