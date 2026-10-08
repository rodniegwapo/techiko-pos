<script setup>
import { computed, ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";
import {
    lineOptions,
    modifierGroupsOf,
    modifierProblem,
} from "@/Composables/useProductModifiers";

/** Pick a product's options (Size, Add-ons…) and a note before it goes into the cart. */
const props = defineProps({
    open: { type: Boolean, default: false },
    product: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "confirm"]);

const { formattedTotal } = useHelpers();

const groups = computed(() => modifierGroupsOf(props.product));
/** group id → picked option id(s): one id for a single choice, an array for several. */
const picks = ref({});
const notes = ref("");

watch(
    () => [props.open, props.product?.id],
    ([open]) => {
        if (!open) return;
        notes.value = "";
        picks.value = Object.fromEntries(
            groups.value.map((g) => [
                g.id,
                // A required single choice starts on its first option, the usual pick.
                g.selection === "single"
                    ? g.is_required
                        ? g.active_modifiers[0]?.id ?? 0
                        : 0
                    : [],
            ]),
        );
    },
    { immediate: true },
);

const pickedIds = computed(() =>
    Object.values(picks.value).flatMap((v) => (Array.isArray(v) ? v : v ? [v] : [])),
);

const problem = computed(() =>
    props.product ? modifierProblem(props.product, pickedIds.value) : null,
);

const line = computed(() =>
    props.product ? lineOptions(props.product, pickedIds.value, notes.value) : null,
);

function deltaLabel(m) {
    const n = Number(m.price_delta) || 0;
    if (!n) return "";
    return n > 0 ? `+${formattedTotal(n)}` : `−${formattedTotal(-n)}`;
}

function maxReached(g) {
    const max = g.max_select;
    return !!max && (picks.value[g.id] || []).length >= max;
}

function confirm() {
    if (problem.value || !line.value) return;
    emit("confirm", line.value);
    emit("update:open", false);
}
</script>

<template>
    <a-modal
        :visible="open"
        :title="product?.name"
        ok-text="Add to order"
        :ok-button-props="{ disabled: !!problem }"
        width="480px"
        centered
        @ok="confirm"
        @cancel="emit('update:open', false)"
    >
        <div class="flex flex-col gap-4" data-testid="modifier-picker">
            <div v-for="g in groups" :key="g.id">
                <div class="mb-1 flex items-center gap-2">
                    <span class="font-medium">{{ g.name }}</span>
                    <a-tag v-if="g.is_required" color="red">Required</a-tag>
                    <span v-else-if="g.selection === 'multiple' && g.max_select" class="text-xs text-gray-500">
                        up to {{ g.max_select }}
                    </span>
                </div>

                <a-radio-group
                    v-if="g.selection === 'single'"
                    v-model:value="picks[g.id]"
                    class="flex flex-col gap-1"
                >
                    <a-radio v-if="!g.is_required" :value="0">None</a-radio>
                    <a-radio v-for="m in g.active_modifiers" :key="m.id" :value="m.id">
                        {{ m.name }} <span class="text-gray-500">{{ deltaLabel(m) }}</span>
                    </a-radio>
                </a-radio-group>

                <a-checkbox-group v-else v-model:value="picks[g.id]" class="flex flex-col gap-1">
                    <a-checkbox
                        v-for="m in g.active_modifiers"
                        :key="m.id"
                        :value="m.id"
                        :disabled="maxReached(g) && !(picks[g.id] || []).includes(m.id)"
                    >
                        {{ m.name }} <span class="text-gray-500">{{ deltaLabel(m) }}</span>
                    </a-checkbox>
                </a-checkbox-group>
            </div>

            <a-input
                v-model:value="notes"
                :maxlength="200"
                placeholder="Note (optional), e.g. less ice"
                aria-label="Note for this item"
            />

            <div class="flex items-center justify-between border-t pt-3">
                <span v-if="problem" class="text-sm text-red-600">{{ problem }}</span>
                <span v-else class="text-sm text-gray-500">Each</span>
                <span class="text-lg font-semibold">{{ formattedTotal(line?.unit_price ?? 0) }}</span>
            </div>
        </div>
    </a-modal>
</template>
