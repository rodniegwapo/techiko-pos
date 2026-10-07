<script setup>
import {
    validationHasError,
    validationMessage,
} from "@/Composables/useValidationMessage.js";

/** "Track stock" switch and "Low stock level" for the product create/edit forms. */
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
        <a-form-item label="Track stock" class="mb-0 sm:w-1/2">
            <div class="flex items-center gap-3">
                <a-switch
                    v-model:checked="form.track_inventory"
                    aria-label="Track stock"
                />
                <span class="text-sm text-gray-600">
                    {{ form.track_inventory ? "On" : "Off" }}
                </span>
            </div>
            <div class="mt-1 text-xs text-gray-500">
                {{
                    form.track_inventory
                        ? "Stock is counted and checked when selling."
                        : "Stock isn't counted — good for made-to-order items like food and drinks."
                }}
            </div>
        </a-form-item>

        <a-form-item
            v-if="form.track_inventory"
            label="Low stock level"
            class="mb-0 sm:w-1/2"
            :validate-status="
                validationHasError(form.errors, 'reorder_level') ? 'error' : ''
            "
            :help="validationMessage(form.errors, 'reorder_level')"
        >
            <a-input-number
                v-model:value="form.reorder_level"
                :min="0"
                :precision="0"
                class="w-full"
                placeholder="0"
                aria-label="Low stock level"
            />
            <div
                v-if="!validationHasError(form.errors, 'reorder_level')"
                class="mt-1 text-xs text-gray-500"
            >
                Marked as low stock when a store has this many or fewer. 0 turns it off.
            </div>
        </a-form-item>
    </div>
</template>
