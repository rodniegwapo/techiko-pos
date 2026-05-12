<script setup>
import { computed, ref } from "vue";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useSharedCatalogLookup } from "@/Composables/useSharedCatalogLookup";
import {
    validationHasError,
    validationMessage,
    validationSummaryNotice,
} from "@/Composables/useValidationMessage.js";
import { message } from "ant-design-vue";
import { useBarcodeScanner } from "@/Composables/useBarcodeScanner";

const page = usePage();
const { getRoute, hrefWithPreservedLocationId } = useDomainRoutes();

const props = defineProps({
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
    subscription: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    name: "",
    domain: "",
    category_id: null,
    cost: null,
    price: null,
    SKU: "",
    barcode: "",
    sold_type: null,
    representation: "",
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

const productsAtCapacity = computed(
    () => props.subscription?.products_at_capacity === true,
);

/**
 * Allow only digits and a single decimal point.
 * This prevents characters like letters, spaces, and scientific notation.
 */
function decimalParser(value) {
    const raw = String(value ?? "");
    const cleaned = raw.replace(/[^\d.]/g, "");
    const parts = cleaned.split(".");
    if (parts.length <= 1) {
        return cleaned;
    }
    return `${parts[0]}.${parts.slice(1).join("")}`;
}

const domainOptions = computed(() => {
    const list = Array.isArray(page?.props?.domains) ? page.props.domains : [];
    return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

const handleSave = () => {
    form.post(hrefWithPreservedLocationId(getRoute("products.store")), {
        onSuccess: () => {
            form.reset();
            form.clearErrors();
            sharedCategoryHint.value = "";
            barcodeLookupNonce.value += 1;
            message.success("Product created successfully");
        },
        onError: (errs) => {
            const bag = errs || form.errors;
            const planMsg = bag?.plan;
            if (planMsg !== undefined && planMsg !== null && planMsg !== "") {
                message.error(
                    Array.isArray(planMsg) ? planMsg[0] : planMsg || "Failed to create product",
                );
                return;
            }
            message.warning(validationSummaryNotice(bag));
        },
    });
};

useBarcodeScanner((code) => {
    form.barcode = code;
    message.success("Barcode Scanned: " + code);
    runBarcodeLookup();
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Create Product" />
        <ContentHeader class="mb-8" title="Create Product" />

        <ContentLayout title="Create Product">
            <template #filters>
                <Link :href="hrefWithPreservedLocationId(getRoute('products.index'))">
                    <a-button>Back to Products</a-button>
                </Link>
            </template>

            <template #table>
                <div class="space-y-4">
                    <a-alert
                        v-if="productsAtCapacity && subscription?.billing_url"
                        type="warning"
                        show-icon
                        message="Product limit reached"
                    >
                        <template #description>
                            <span class="mr-1">Subscribe for unlimited products on this domain.</span>
                            <a :href="subscription.billing_url">Open servicing payment</a>
                        </template>
                    </a-alert>

                    <div class="max-w-2xl mx-auto p-6 bg-white rounded-lg shadow">
                    <a-form layout="vertical">
                        <!-- Product Name -->
                        <a-form-item
                            label="Product Name"
                            required
                            :validate-status="
                                validationHasError(form.errors, 'name')
                                    ? 'error'
                                    : ''
                            "
                            :help="validationMessage(form.errors, 'name')"
                        >
                            <a-input
                                v-model:value="form.name"
                                placeholder="Enter product name"
                                size="large"
                            />
                        </a-form-item>

                        <!-- Domain (conditional for global view) -->
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
                        >
                            <a-select
                                v-model:value="form.domain"
                                :options="domainOptions"
                                placeholder="Select domain"
                                size="large"
                            />
                        </a-form-item>

                        <!-- Category -->
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
                                :allow-clear="true"
                            />
                        </a-form-item>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Cost -->
                            <a-form-item
                                label="Cost"
                                :validate-status="
                                    validationHasError(form.errors, 'cost')
                                        ? 'error'
                                        : ''
                                "
                                :help="validationMessage(form.errors, 'cost')"
                            >
                                <a-input-number
                                    v-model:value="form.cost"
                                    placeholder="Enter cost"
                                    :min="0"
                                    :precision="2"
                                    :parser="decimalParser"
                                    style="width: 100%"
                                    size="large"
                                />
                            </a-form-item>

                            <!-- Price -->
                            <a-form-item
                                label="Price"
                                required
                                :validate-status="
                                    validationHasError(form.errors, 'price')
                                        ? 'error'
                                        : ''
                                "
                                :help="validationMessage(form.errors, 'price')"
                            >
                                <a-input-number
                                    v-model:value="form.price"
                                    placeholder="Enter price"
                                    :min="0"
                                    :precision="2"
                                    :parser="decimalParser"
                                    style="width: 100%"
                                    size="large"
                                />
                            </a-form-item>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- SKU -->
                            <a-form-item
                                label="SKU"
                                :required="props.isGlobalView"
                                :validate-status="
                                    validationHasError(form.errors, 'SKU')
                                        ? 'error'
                                        : ''
                                "
                                :help="validationMessage(form.errors, 'SKU')"
                            >
                                <a-input
                                    v-model:value="form.SKU"
                                    placeholder="Enter SKU"
                                    size="large"
                                />
                            </a-form-item>

                            <!-- Barcode -->
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
                            >
                                <a-input
                                    v-model:value="form.barcode"
                                    placeholder="Enter barcode"
                                    size="large"
                                />
                            </a-form-item>
                        </div>

                        <div v-if="domainLookupEnabled" class="space-y-2 mb-4">
                            <a-alert
                                v-if="lookupLoading"
                                type="info"
                                message="Checking shared catalog…"
                            />
                            <a-alert
                                v-else-if="catalogFound"
                                type="success"
                                message=" Review prefilled fields before saving."
                                show-icon
                            />
                            <a-alert
                                v-if="domainLookupEnabled && sharedCategoryHint"
                                type="info"
                                :message="`Suggested category (hint only): ${sharedCategoryHint}`"
                            />
                        </div>

                        <!-- Sold Type -->
                        <a-form-item
                            label="Sold Type"
                            required
                            :validate-status="
                                validationHasError(form.errors, 'sold_type')
                                    ? 'error'
                                    : ''
                            "
                            :help="validationMessage(form.errors, 'sold_type')"
                        >
                            <a-radio-group
                                v-model:value="form.sold_type"
                                size="large"
                            >
                                <a-radio
                                    v-for="option in soltTypeOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </a-radio>
                            </a-radio-group>
                        </a-form-item>

                        <!-- Optional custom color hex; server defaults type=color + neutral hex when empty -->
                        <a-form-item
                            label="Color (hex, optional)"
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
                                ) || 'Leave blank to use the default color'
                            "
                        >
                            <a-input
                                v-model:value="form.representation"
                                placeholder="e.g. ff5733 (no #)"
                                size="large"
                            />
                        </a-form-item>

                        <div class="flex justify-end gap-2 mt-4">
                            <Link :href="hrefWithPreservedLocationId(getRoute('products.index'))">
                                <a-button>Cancel</a-button>
                            </Link>
                            <a-button
                                type="primary"
                                :loading="form.processing"
                                :disabled="productsAtCapacity"
                                @click="handleSave"
                                >Create Product</a-button
                            >
                        </div>
                    </a-form>
                </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
