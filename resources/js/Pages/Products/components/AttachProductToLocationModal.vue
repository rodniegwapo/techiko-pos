<script setup>
import axios from "axios";
import { ref, watch } from "vue";
import { watchDebounced } from "@vueuse/core";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { message } from "ant-design-vue";

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    locationId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits(["update:visible", "attached"]);

const { getRoute } = useDomainRoutes();

const modalSearch = ref("");
const loading = ref(false);
const attachingId = ref(null);
const results = ref([]);

/** Laravel wraps ProductResource::collection in an extra `data` key. */
function assignableRowsFromResponse(resData) {
    const inner = resData?.data;
    if (Array.isArray(inner?.data)) {
        return inner.data;
    }
    if (Array.isArray(inner)) {
        return inner;
    }
    return [];
}

async function fetchAssignable() {
    if (!props.visible || props.locationId == null || props.locationId === "") {
        return;
    }
    loading.value = true;
    try {
        const assignableUrl = getRoute("products.assignable");
        if (!assignableUrl || assignableUrl === "#") {
            throw new Error("Missing route");
        }
        const res = await axios.get(assignableUrl, {
            params: {
                location_id: props.locationId,
                search: modalSearch.value?.trim() || undefined,
            },
        });
        results.value = assignableRowsFromResponse(res.data);
    } catch {
        results.value = [];
        message.error("Could not load products for this store.");
    } finally {
        loading.value = false;
    }
}

function syncAssignableResultsIfOpen() {
    if (!props.visible || props.locationId == null || props.locationId === "") {
        return;
    }
    return fetchAssignable();
}

watchDebounced(
    () => modalSearch.value,
    () => {
        syncAssignableResultsIfOpen();
    },
    { debounce: 350, flush: "post" },
);

watch(
    () => [props.visible, props.locationId],
    ([isVisible]) => {
        if (isVisible && props.locationId != null && props.locationId !== "") {
            modalSearch.value = "";
            syncAssignableResultsIfOpen();
        }
        if (!isVisible) {
            modalSearch.value = "";
            results.value = [];
        }
    },
);

function close() {
    emit("update:visible", false);
}

async function attach(row) {
    if (
        attachingId.value != null ||
        props.locationId == null ||
        props.locationId === ""
    ) {
        return;
    }
    const attachUrl = getRoute("products.attach-location", { product: row.id });
    if (!attachUrl || attachUrl === "#") {
        message.error("Missing attach route.");
        return;
    }
    attachingId.value = row.id;
    try {
        const { data } = await axios.post(attachUrl, {
            location_id: props.locationId,
        });
        if (data?.already_attached) {
            message.info(`${row.name} is already at this store.`);
        } else {
            message.success(`${row.name} added to this store.`);
        }
        emit("attached");
        close();
    } catch (e) {
        const msg =
            e.response?.data?.message ||
            e.response?.data?.errors?.product_id?.[0] ||
            e.message ||
            "Could not attach product.";
        message.error(Array.isArray(msg) ? msg[0] : msg);
    } finally {
        attachingId.value = null;
    }
}

function categoryLabel(p) {
    return p?.category?.name ?? "Uncategorized";
}
</script>

<template>
    <a-modal
        :visible="visible"
        title="Add existing product to this store"
        destroy-on-close
        width="640px"
        :footer="null"
        @cancel="close"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <p class="text-gray-600 text-sm mb-3">
            Show products already offered at another store in your organization but
            not at this location. Search by name, SKU, or barcode. This does not
            create a duplicate SKU.
        </p>
        <a-input-search
            v-model:value="modalSearch"
            placeholder="Search by name, SKU, barcode…"
            class="mb-4"
            :loading="loading"
            allow-clear
            @search="fetchAssignable"
        />
        <a-spin :spinning="loading">
            <div
                v-if="!results.length && !loading"
                class="text-gray-500 text-sm py-6 text-center"
            >
                No matching products. Either nothing is offered elsewhere yet that
                matches your search, or every match is already at this store. Try a
                different search.
            </div>
            <div v-else class="flex flex-col gap-2 max-h-[360px] overflow-y-auto">
                <div
                    v-for="p in results"
                    :key="p.id"
                    class="flex items-center justify-between border rounded px-3 py-2 gap-3"
                >
                    <div class="min-w-0 flex-1">
                        <div class="font-medium truncate">{{ p.name }}</div>
                        <div class="text-xs text-gray-500 truncate">
                            {{ categoryLabel(p) }}
                            <span v-if="p.SKU"> · SKU {{ p.SKU }}</span>
                            <span v-if="p.barcode"> · {{ p.barcode }}</span>
                        </div>
                    </div>
                    <a-button
                        type="primary"
                        size="small"
                        :loading="attachingId === p.id"
                        :disabled="attachingId !== null && attachingId !== p.id"
                        @click="attach(p)"
                    >
                        Add to store
                    </a-button>
                </div>
            </div>
        </a-spin>
    </a-modal>
</template>
