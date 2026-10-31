<script setup>
import { computed, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useBusinessType } from "@/Composables/useBusinessType";

/**
 * The product form's "Modifiers" field: which option groups (Size, Add-ons…) the cashier picks from.
 * Businesses that rarely use them (retail, grocery, services) see a link instead until they ask for
 * it; a product that already has modifiers always shows them.
 */
const props = defineProps({
    form: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
});

const { getRoute } = useDomainRoutes();
const { preset } = useBusinessType();
const expanded = ref(preset.value.showModifiers || (props.form.modifier_group_ids?.length ?? 0) > 0);

const options = computed(() =>
    props.groups.map((g) => ({
        value: g.id,
        label: `${g.name}${g.is_required ? " (required)" : ""}`,
    })),
);
</script>

<template>
    <a-button
        v-if="!expanded && !form.modifier_group_ids?.length"
        type="link"
        class="mb-4 h-auto !px-0"
        data-testid="show-modifiers"
        @click="expanded = true"
    >
        + Add modifiers (size, add-ons…)
    </a-button>
    <a-form-item
        v-else
        label="Modifiers"
        data-testid="modifiers-field"
        :validate-status="form.errors?.modifier_group_ids ? 'error' : ''"
        :help="form.errors?.modifier_group_ids"
    >
        <a-select
            v-model:value="form.modifier_group_ids"
            mode="multiple"
            :options="options"
            option-filter-prop="label"
            placeholder="None — sold as is"
            allow-clear
        />
        <div class="mt-1 text-xs text-gray-500">
            Options picked at the POS, such as Size or Add-ons.
            <Link :href="getRoute('products.modifier-groups.index')" class="text-blue-600">
                {{ groups.length ? "Manage modifiers" : "Create modifier groups" }}
            </Link>
        </div>
    </a-form-item>
</template>
