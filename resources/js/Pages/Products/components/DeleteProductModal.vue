<script setup>
import { computed, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const props = defineProps({
    open: { type: Boolean, default: false },
    product: { type: Object, default: null },
    currentLocation: { type: Object, default: null },
});

const emit = defineEmits(["update:open"]);

const { getRoute } = useDomainRoutes();
const page = usePage();

// Only an organization page has a store to remove the product from; the global route deletes everywhere.
const canRemoveFromStore = computed(
    () => !!props.currentLocation && !page.props.isGlobalView,
);

const scope = ref("store");
const submitting = ref(false);

const storeName = computed(() => props.currentLocation?.name || "this store");

/** Stock this store still holds; removing it from the store waits until this is 0. */
const stockHere = computed(() => {
    if (!props.product?.track_inventory) return 0;
    return Number(props.product?.location_quantity_on_hand) || 0;
});

const otherStores = computed(() =>
    Math.max(0, (Number(props.product?.store_count) || 1) - 1),
);

// A product in no store can't be found again under "Add existing to store",
// so its last store can only be left by deleting it everywhere.
const onlyStore = computed(() => otherStores.value === 0);

watch(
    () => props.open,
    (open) => {
        if (open) {
            scope.value =
                canRemoveFromStore.value && !onlyStore.value ? "store" : "all";
        }
    },
);

const blocked = computed(() => scope.value === "store" && stockHere.value > 0);

const okText = computed(() =>
    scope.value === "store" ? "Remove from store" : "Delete everywhere",
);

const close = () => emit("update:open", false);

const submit = () => {
    if (!props.product || blocked.value) return;

    submitting.value = true;
    router.delete(getRoute("products.destroy", { product: props.product.id }), {
        data:
            scope.value === "store"
                ? { scope: "store", location_id: props.currentLocation?.id }
                : { scope: "all" },
        preserveScroll: true,
        onSuccess: (page) => {
            // A refusal comes back as a redirect carrying flash.error.
            const error = page.props?.flash?.error;
            if (error) {
                notification.error({ message: "Not removed", description: error });
                return;
            }
            notification.success({
                message: "Success",
                description: page.props?.flash?.success || "Product deleted successfully",
            });
            close();
        },
        onError: () => {
            notification.error({
                message: "Error",
                description: "The product could not be removed. Please try again.",
            });
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
};
</script>

<template>
    <a-modal
        :visible="open"
        title="Delete product"
        :ok-text="okText"
        :ok-button-props="{ danger: true, disabled: blocked }"
        :confirm-loading="submitting"
        cancel-text="Cancel"
        @ok="submit"
        @cancel="close"
    >
        <p class="mb-3">
            How do you want to delete <strong>{{ product?.name }}</strong>?
        </p>

        <a-radio-group v-model:value="scope" class="flex w-full flex-col gap-3">
            <a-radio
                v-if="canRemoveFromStore"
                value="store"
                class="items-start"
                :disabled="onlyStore"
            >
                <div>
                    <div class="font-medium">Remove from {{ storeName }} only</div>
                    <div v-if="onlyStore" class="text-xs text-gray-500">
                        Not available: {{ storeName }} is the only store with this product.
                    </div>
                    <div v-else class="text-xs text-gray-500">
                        It stays in your organization and in {{ otherStores }} other
                        {{ otherStores === 1 ? "store" : "stores" }}. You can add it back
                        here with "Add existing to store".
                    </div>
                </div>
            </a-radio>
            <a-radio value="all" class="items-start">
                <div>
                    <div class="font-medium">Delete everywhere</div>
                    <div class="text-xs text-gray-500">
                        Permanently removes it from your organization and every store.
                        This can't be undone.
                    </div>
                </div>
            </a-radio>
        </a-radio-group>

        <a-alert
            v-if="blocked"
            class="mt-4"
            type="warning"
            show-icon
            :message="`${storeName} still has ${stockHere} in stock`"
            description="Transfer the stock to another store or adjust it to 0 before removing the product from this store."
        />
    </a-modal>
</template>
