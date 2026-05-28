<script setup>
import { computed, ref } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useBarcodeScanner } from "@/Composables/useBarcodeScanner";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useSharedCatalogLookup } from "@/Composables/useSharedCatalogLookup";
import {
    validationHasError,
    validationMessage,
    validationSummaryNotice,
} from "@/Composables/useValidationMessage.js";
import { ArrowLeftOutlined, SaveOutlined } from "@ant-design/icons-vue";
import { message } from "ant-design-vue";

const page = usePage();
const { getRoute, hrefWithPreservedLocationId } = useDomainRoutes();

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    sold_by_types: {
        type: Array,
        default: () => [],
    },
    isGlobalView: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    id: props.product.id,
    name: props.product.name,
    domain: props.product.domain,
    category_id: props.product.category_id,
    cost: props.product.cost,
    price: props.product.price,
    SKU: props.product.SKU,
    barcode: props.product.barcode,
    sold_type: props.product.sold_type,
    representation_type: props.product.representation_type,
    representation: props.product.representation,
});

const domainSlug = computed(() => {
    if (props.isGlobalView) {
        return null;
    }
    const m =
        typeof window !== "undefined"
            ? window.location.pathname.match(/\/domains\/([^/]+)/)
            : null;
    if (m) {
        return m[1];
    }
    return page.props.currentDomain?.name_slug ?? null;
});

const domainLookupEnabled = computed(
    () => !props.isGlobalView && !!domainSlug.value,
);

const sharedCategoryHint = ref("");
const barcodeLookupNonce = ref(0);

function assignSharedCatalogFields(data) {
    if (data.name) {
        form.name = data.name;
    }
    sharedCategoryHint.value = data.category_label || "";
    if (
        data.sold_type &&
        props.sold_by_types.some((s) => s.name === data.sold_type)
    ) {
        form.sold_type = data.sold_type;
    }
}

const { lookupLoading, catalogFound, lookup } = useSharedCatalogLookup({
    enabled: domainLookupEnabled,
    getDomainSlug: () => domainSlug.value,
});

async function runBarcodeLookup() {
    barcodeLookupNonce.value += 1;
    const nonce = barcodeLookupNonce.value;
    sharedCategoryHint.value = "";
    await lookup(form.barcode, assignSharedCatalogFields);
    if (nonce !== barcodeLookupNonce.value) {
        return;
    }
}

watchDebounced(() => form.barcode, runBarcodeLookup, {
    debounce: 450,
});

const categoriesOption = computed(() => {
    return props.categories.map((item) => ({
        label: item.name,
        value: item.id,
    }));
});

const soltTypeOptions = computed(() => {
    return props.sold_by_types.map((item) => item.name);
});

const domainOptions = computed(() => {
    const list = Array.isArray(page?.props?.domains) ? page.props.domains : [];
    return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

const representationHexColor = computed(() => {
    const raw = String(form.representation || "")
        .trim()
        .replace(/^#/, "");
    if (/^[0-9a-fA-F]{3}$/.test(raw) || /^[0-9a-fA-F]{6}$/.test(raw)) {
        return `#${raw}`;
    }
    return null;
});

const handleUpdate = () => {
    form.put(
        hrefWithPreservedLocationId(
            getRoute("products.update", { product: props.product.id }),
        ),
        {
            onSuccess: () => {
                message.success("Product updated successfully");
            },
            onError: (errs) => {
                const bag = errs || form.errors;
                const planMsg = bag?.plan;
                if (planMsg !== undefined && planMsg !== null && planMsg !== "") {
                    message.error(
                        Array.isArray(planMsg) ? planMsg[0] : planMsg || "Failed to update product",
                    );
                    return;
                }
                message.warning(validationSummaryNotice(bag));
            },
        },
    );
};

useBarcodeScanner((code) => {
    form.barcode = code;
    message.success("Barcode Scanned: " + code);
    runBarcodeLookup();
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Edit Product" />
        <ContentHeader class="mb-4 md:mb-8" title="Edit Product">
            <template #meta>
                {{ product.name }} · SKU {{ product.SKU || "—" }}
            </template>
            <template #actions>
                <Link
                    class="block w-full md:w-auto"
                    :href="hrefWithPreservedLocationId(getRoute('products.index'))"
                >
                    <a-button
                        class="flex w-full items-center justify-center md:inline-flex md:w-auto"
                    >
                        <template #icon>
                            <ArrowLeftOutlined />
                        </template>
                        Back to Products
                    </a-button>
                </Link>
            </template>
        </ContentHeader>

        <ContentLayout title="" filter-main-class="hidden">
            <template #table>
                <div
                    class="mx-auto w-full min-w-0 max-w-2xl px-4 pb-6 md:px-6 md:pb-8"
                >
                    <div
                        class="mb-6 hidden flex-wrap items-center gap-2 border-b border-gray-100 pb-4 md:flex"
                    >
                        <div
                            v-if="
                                form.representation_type === 'color' &&
                                representationHexColor
                            "
                            class="h-8 w-8 shrink-0 rounded border border-gray-200"
                            :style="{ backgroundColor: representationHexColor }"
                        />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-lg font-semibold text-gray-900">
                                {{ product.name }}
                            </h2>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <a-tag v-if="product.SKU" color="blue" class="text-xs">
                                    {{ product.SKU }}
                                </a-tag>
                                <a-tag
                                    v-if="product.sold_type"
                                    color="purple"
                                    class="text-xs"
                                >
                                    {{ product.sold_type }}
                                </a-tag>
                            </div>
                        </div>
                    </div>

                    <a-form layout="vertical" class="product-form space-y-6">
                        <section class="space-y-4 rounded-lg bg-gray-50 p-4">
                            <h4 class="font-semibold text-gray-900">
                                Basic information
                            </h4>
                            <a-form-item
                                label="Product Name"
                                required
                                :validate-status="
                                    validationHasError(form.errors, 'name')
                                        ? 'error'
                                        : ''
                                "
                                :help="validationMessage(form.errors, 'name')"
                                class="mb-0"
                            >
                                <a-input
                                    v-model:value="form.name"
                                    placeholder="Enter product name"
                                    size="large"
                                />
                            </a-form-item>

                            <a-form-item
                                v-if="props.isGlobalView"
                                label="Domain"
                                required
                                :validate-status="
                                    validationHasError(form.errors, 'domain')
                                        ? 'error'
                                        : ''
                                "
                                :help="validationMessage(form.errors, 'domain')"
                                class="mb-0"
                            >
                                <a-select
                                    v-model:value="form.domain"
                                    :options="domainOptions"
                                    placeholder="Select domain"
                                    size="large"
                                />
                            </a-form-item>

                            <a-form-item
                                label="Category (optional)"
                                :validate-status="
                                    validationHasError(form.errors, 'category_id')
                                        ? 'error'
                                        : ''
                                "
                                :help="
                                    validationMessage(form.errors, 'category_id')
                                "
                                class="mb-0"
                            >
                                <a-select
                                    v-model:value="form.category_id"
                                    :options="categoriesOption"
                                    placeholder="Select category or leave blank"
                                    allow-clear
                                    show-search
                                    :filter-option="
                                        (input, option) =>
                                            option.label
                                                .toLowerCase()
                                                .includes(input.toLowerCase())
                                    "
                                    size="large"
                                />
                            </a-form-item>
                        </section>

                        <section class="space-y-4 rounded-lg bg-gray-50 p-4">
                            <h4 class="font-semibold text-gray-900">Pricing</h4>
                            <div
                                class="space-y-4 md:grid md:grid-cols-2 md:gap-4 md:space-y-0"
                            >
                                <a-form-item
                                    label="Cost"
                                    :validate-status="
                                        validationHasError(form.errors, 'cost')
                                            ? 'error'
                                            : ''
                                    "
                                    :help="validationMessage(form.errors, 'cost')"
                                    class="mb-0"
                                >
                                    <a-input-number
                                        v-model:value="form.cost"
                                        placeholder="Enter cost"
                                        :min="0"
                                        :precision="2"
                                        size="large"
                                    />
                                </a-form-item>

                                <a-form-item
                                    label="Price"
                                    required
                                    :validate-status="
                                        validationHasError(form.errors, 'price')
                                            ? 'error'
                                            : ''
                                    "
                                    :help="validationMessage(form.errors, 'price')"
                                    class="mb-0"
                                >
                                    <a-input-number
                                        v-model:value="form.price"
                                        placeholder="Enter price"
                                        :min="0"
                                        :precision="2"
                                        size="large"
                                    />
                                </a-form-item>
                            </div>
                        </section>

                        <section class="space-y-4 rounded-lg bg-gray-50 p-4">
                            <h4 class="font-semibold text-gray-900">
                                Identification
                            </h4>
                            <div
                                class="space-y-4 md:grid md:grid-cols-2 md:gap-4 md:space-y-0"
                            >
                                <a-form-item
                                    label="SKU"
                                    :required="props.isGlobalView"
                                    :validate-status="
                                        validationHasError(form.errors, 'SKU')
                                            ? 'error'
                                            : ''
                                    "
                                    :help="validationMessage(form.errors, 'SKU')"
                                    class="mb-0"
                                >
                                    <a-input
                                        v-model:value="form.SKU"
                                        placeholder="Enter SKU"
                                        size="large"
                                    />
                                </a-form-item>

                                <a-form-item
                                    label="Barcode"
                                    :required="props.isGlobalView"
                                    :validate-status="
                                        validationHasError(form.errors, 'barcode')
                                            ? 'error'
                                            : ''
                                    "
                                    :help="
                                        validationMessage(form.errors, 'barcode')
                                    "
                                    class="mb-0"
                                >
                                    <a-input
                                        v-model:value="form.barcode"
                                        placeholder="Enter barcode"
                                        size="large"
                                    />
                                </a-form-item>
                            </div>

                            <div
                                v-if="domainLookupEnabled"
                                class="space-y-2"
                            >
                                <a-alert
                                    v-if="lookupLoading"
                                    type="info"
                                    message="Checking shared catalog…"
                                />
                                <a-alert
                                    v-else-if="catalogFound"
                                    type="success"
                                    message="Barcode matches shared catalog."
                                    show-icon
                                />
                                <a-alert
                                    v-if="
                                        domainLookupEnabled && sharedCategoryHint
                                    "
                                    type="info"
                                    :message="`Suggested category (hint only): ${sharedCategoryHint}`"
                                />
                            </div>
                        </section>

                        <section class="space-y-4 rounded-lg bg-gray-50 p-4">
                            <h4 class="font-semibold text-gray-900">
                                Display & type
                            </h4>
                            <a-form-item
                                label="Sold Type"
                                required
                                :validate-status="
                                    validationHasError(form.errors, 'sold_type')
                                        ? 'error'
                                        : ''
                                "
                                :help="
                                    validationMessage(form.errors, 'sold_type')
                                "
                                class="mb-0"
                            >
                                <a-radio-group
                                    v-model:value="form.sold_type"
                                    size="large"
                                    class="flex w-full flex-col gap-2 md:flex-row md:flex-wrap md:gap-x-4 md:gap-y-2"
                                >
                                    <a-radio
                                        v-for="option in soltTypeOptions"
                                        :key="option"
                                        :value="option"
                                        class="!m-0 !flex !w-full !items-center rounded-md border border-gray-200 bg-white px-3 py-2.5 md:!w-auto md:!border-0 md:!bg-transparent md:!px-0 md:!py-0"
                                    >
                                        {{ option }}
                                    </a-radio>
                                </a-radio-group>
                            </a-form-item>

                            <div
                                class="space-y-4 md:grid md:grid-cols-2 md:gap-4 md:space-y-0"
                            >
                                <a-form-item
                                    label="Representation Type"
                                    :validate-status="
                                        validationHasError(
                                            form.errors,
                                            'representation_type',
                                        )
                                            ? 'error'
                                            : ''
                                    "
                                    :help="
                                        validationMessage(
                                            form.errors,
                                            'representation_type',
                                        )
                                    "
                                    class="mb-0"
                                >
                                    <a-select
                                        v-model:value="form.representation_type"
                                        :options="[
                                            { label: 'Color', value: 'color' },
                                        ]"
                                        placeholder="Select representation type"
                                        size="large"
                                    />
                                </a-form-item>

                                <a-form-item
                                    label="Representation"
                                    :validate-status="
                                        validationHasError(
                                            form.errors,
                                            'representation',
                                        )
                                            ? 'error'
                                            : ''
                                    "
                                    :help="
                                        validationMessage(
                                            form.errors,
                                            'representation',
                                        )
                                    "
                                    class="mb-0"
                                >
                                    <div
                                        class="flex flex-col gap-2 md:flex-row md:items-center"
                                    >
                                        <a-input
                                            v-model:value="form.representation"
                                            placeholder="Enter representation (e.g., hex color code)"
                                            size="large"
                                            class="min-w-0 w-full flex-1"
                                        />
                                        <div
                                            v-if="representationHexColor"
                                            class="h-10 w-full shrink-0 rounded border border-gray-200 md:w-10"
                                            :style="{
                                                backgroundColor:
                                                    representationHexColor,
                                            }"
                                        />
                                    </div>
                                </a-form-item>
                            </div>
                        </section>

                        <div
                            class="mt-2 flex flex-col-reverse gap-2 border-t border-gray-200 pt-6 md:mt-8 md:flex-row md:justify-end"
                        >
                            <Link
                                class="block w-full md:w-auto"
                                :href="hrefWithPreservedLocationId(getRoute('products.index'))"
                            >
                                <a-button class="w-full md:w-auto"
                                    >Cancel</a-button
                                >
                            </Link>
                            <a-button
                                type="primary"
                                class="w-full md:w-auto"
                                :loading="form.processing"
                                @click="handleUpdate"
                            >
                                <template #icon>
                                    <SaveOutlined />
                                </template>
                                Update Product
                            </a-button>
                        </div>
                    </a-form>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>

<style scoped>
.product-form :deep(.ant-input-number),
.product-form :deep(.ant-select),
.product-form :deep(.ant-input) {
    width: 100%;
}
</style>
