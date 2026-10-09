<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/** The product form's "Modifiers" field: which option groups (Size, Add-ons…) the cashier picks from. */
const props = defineProps({
    form: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
});

const { getRoute } = useDomainRoutes();

const options = computed(() =>
    props.groups.map((g) => ({
        value: g.id,
        label: `${g.name}${g.is_required ? " (required)" : ""}`,
    })),
);
</script>

<template>
    <a-form-item
        label="Modifiers"
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
