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
import { ArrowLeftOutlined, PlusOutlined } from "@ant-design/icons-vue";
import { message } from "ant-design-vue";
import { useHiddenBarcodeCapture } from "@/Composables/useHiddenBarcodeCapture";

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
    representation_type: "color",
    representation: "",
    representation_image: null,
});

const imageFileList = ref([]);

function beforeImageUpload(file) {
    const raw = file?.originFileObj instanceof File ? file.originFileObj : file;
    if (!(raw instanceof File)) {
        message.error("Please choose a valid image file.");
        return false;
    }
    form.representation_image = raw;
    form.representation = "";
    imageFileList.value = [
        {
            uid: String(file.uid || Date.now()),
            name: raw.name,
            status: "done",
            url: URL.createObjectURL(raw),
        },
    ];
    return false;
}

function onImageRemove() {
    form.representation_image = null;
    form.representation = "";
    imageFileList.value = [];
}

function onRepresentationTypeChange(type) {
    form.representation_type = type;
    if (type === "color") {
        onImageRemove();
        form.representation = "";
    } else {
        form.representation = "";
    }
}

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

const {
    scannerBuffer,
    captureInputRef,
    formContainerRef,
    onCaptureEnter,
    focusCapture,
} = useHiddenBarcodeCapture((code) => {
    form.barcode = code;
    message.success("Barcode scanned: " + code);
    runBarcodeLookup();
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

const representationHexColor = computed(() => {
    const raw = String(form.representation || "")
        .trim()
        .replace(/^#/, "");
    if (/^[0-9a-fA-F]{3}$/.test(raw) || /^[0-9a-fA-F]{6}$/.test(raw)) {
        return `#${raw}`;
    }
    return null;
});

const handleSave = () => {
    form.transform((data) => {
        const payload = { ...data };
        if (!(payload.representation_image instanceof File)) {
            delete payload.representation_image;
        }
        if (payload.representation_type !== "image") {
            delete payload.representation_image;
        }
        return payload;
    }).post(hrefWithPreservedLocationId(getRoute("products.store")), {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            form.representation_type = "color";
            form.representation_image = null;
            form.clearErrors();
            imageFileList.value = [];
            sharedCategoryHint.value = "";
            barcodeLookupNonce.value += 1;
            message.success("Product created successfully");
            focusCapture();
        },
        onError: (errs) => {
            const bag = errs || form.errors;
            const planMsg = bag?.plan;
            if (planMsg !== undefined && planMsg !== null && planMsg !== "") {
                message.error(
                    Array.isArray(planMsg)
                        ? planMsg[0]
                        : planMsg || "Failed to create product",
                );
                return;
            }
            message.warning(validationSummaryNotice(bag));
        },
        onFinish: () => {
            form.transform((data) => data);
        },
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Create Product" />
        <ContentHeader class="mb-4 md:mb-8" title="Create Product">
            <template #meta>Add a new product to this domain</template>
            <template #actions>
                <Link
                    class="block w-full md:w-auto"
                    :href="
                        hrefWithPreservedLocationId(getRoute('products.index'))
                    "
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
                    class="mx-auto w-full min-w-0 max-w-2xl space-y-4 px-4 pb-6 md:px-6 md:pb-8"
                >
                    <a-alert
                        v-if="productsAtCapacity && subscription?.billing_url"
                        type="warning"
                        show-icon
                        message="Product limit reached"
                    >
                        <template #description>
                            <span class="mr-1"
                                >Subscribe for unlimited products on this
                                domain.</span
                            >
                            <a :href="subscription.billing_url"
                                >Open servicing payment</a
                            >
                        </template>
                    </a-alert>

                    <div ref="formContainerRef">
                        <a-form
                            layout="vertical"
                            class="product-form space-y-6"
                        >
                            <section
                                class="space-y-4 rounded-lg bg-gray-50 p-4"
                            >
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
                                    :help="
                                        validationMessage(form.errors, 'name')
                                    "
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
                                        validationHasError(
                                            form.errors,
                                            'domain',
                                        )
                                            ? 'error'
                                            : ''
                                    "
                                    :help="
                                        validationMessage(form.errors, 'domain')
                                    "
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
                                        validationHasError(
                                            form.errors,
                                            'category_id',
                                        )
                                            ? 'error'
                                            : ''
                                    "
                                    :help="
                                        validationMessage(
                                            form.errors,
                                            'category_id',
                                        )
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
                                                    .includes(
                                                        input.toLowerCase(),
                                                    )
                                        "
                                        size="large"
                                        :allow-clear="true"
                                    />
                                </a-form-item>
                            </section>

                            <section
                                class="space-y-4 rounded-lg bg-gray-50 p-4"
                            >
                                <h4 class="font-semibold text-gray-900">
                                    Pricing
                                </h4>
                                <div
                                    class="space-y-4 md:grid md:grid-cols-2 md:gap-4 md:space-y-0"
                                >
                                    <a-form-item
                                        label="Cost"
                                        :validate-status="
                                            validationHasError(
                                                form.errors,
                                                'cost',
                                            )
                                                ? 'error'
                                                : ''
                                        "
                                        :help="
                                            validationMessage(
                                                form.errors,
                                                'cost',
                                            )
                                        "
                                        class="mb-0"
                                    >
                                        <a-input-number
                                            v-model:value="form.cost"
                                            placeholder="Enter cost"
                                            :min="0"
                                            :precision="2"
                                            :parser="decimalParser"
                                            size="large"
                                        />
                                    </a-form-item>

                                    <a-form-item
                                        label="Price"
                                        required
                                        :validate-status="
                                            validationHasError(
                                                form.errors,
                                                'price',
                                            )
                                                ? 'error'
                                                : ''
                                        "
                                        :help="
                                            validationMessage(
                                                form.errors,
                                                'price',
                                            )
                                        "
                                        class="mb-0"
                                    >
                                        <a-input-number
                                            v-model:value="form.price"
                                            placeholder="Enter price"
                                            :min="0"
                                            :precision="2"
                                            :parser="decimalParser"
                                            size="large"
                                        />
                                    </a-form-item>
                                </div>
                            </section>

                            <section
                                class="space-y-4 rounded-lg bg-gray-50 p-4"
                            >
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
                                            validationHasError(
                                                form.errors,
                                                'SKU',
                                            )
                                                ? 'error'
                                                : ''
                                        "
                                        :help="
                                            validationMessage(
                                                form.errors,
                                                'SKU',
                                            )
                                        "
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
                                            validationHasError(
                                                form.errors,
                                                'barcode',
                                            )
                                                ? 'error'
                                                : ''
                                        "
                                        :help="
                                            validationMessage(
                                                form.errors,
                                                'barcode',
                                            )
                                        "
                                        class="mb-0"
                                    >
                                        <input
                                            ref="captureInputRef"
                                            v-model="scannerBuffer"
                                            type="text"
                                            tabindex="-1"
                                            aria-hidden="true"
                                            autocomplete="off"
                                            class="fixed left-0 top-0 h-px w-px overflow-hidden opacity-0"
                                            @keydown.enter="onCaptureEnter"
                                        />
                                        <!-- <p class="mb-2 text-xs text-gray-500">
                                        Scan while this page is active, or type
                                        barcode manually below.
                                    </p> -->
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
                                        message="Review prefilled fields before saving."
                                        show-icon
                                    />
                                    <a-alert
                                        v-if="
                                            domainLookupEnabled &&
                                            sharedCategoryHint
                                        "
                                        type="info"
                                        :message="`Suggested category (hint only): ${sharedCategoryHint}`"
                                    />
                                </div>
                            </section>

                            <section
                                class="space-y-4 rounded-lg bg-gray-50 p-4"
                            >
                                <h4 class="font-semibold text-gray-900">
                                    Display & type
                                </h4>
                                <a-form-item
                                    label="Sold Type"
                                    required
                                    :validate-status="
                                        validationHasError(
                                            form.errors,
                                            'sold_type',
                                        )
                                            ? 'error'
                                            : ''
                                    "
                                    :help="
                                        validationMessage(
                                            form.errors,
                                            'sold_type',
                                        )
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

                                <a-form-item
                                    label="Display style"
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
                                    <a-radio-group
                                        v-model:value="form.representation_type"
                                        size="large"
                                        class="flex w-full flex-col gap-2 md:flex-row md:gap-4"
                                        @change="
                                            (e) =>
                                                onRepresentationTypeChange(
                                                    e?.target?.value ??
                                                        form.representation_type,
                                                )
                                        "
                                    >
                                        <a-radio
                                            value="color"
                                            class="!m-0 !flex !w-full !items-center rounded-md border border-gray-200 bg-white px-3 py-2.5 md:!w-auto"
                                        >
                                            Color
                                        </a-radio>
                                        <a-radio
                                            value="image"
                                            class="!m-0 !flex !w-full !items-center rounded-md border border-gray-200 bg-white px-3 py-2.5 md:!w-auto"
                                        >
                                            Image
                                        </a-radio>
                                    </a-radio-group>
                                </a-form-item>

                                <a-form-item
                                    v-if="form.representation_type === 'color'"
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
                                        ) ||
                                        'Leave blank to use the default color'
                                    "
                                    class="mb-0"
                                >
                                    <div
                                        class="flex flex-col gap-2 md:flex-row md:items-center"
                                    >
                                        <a-input
                                            v-model:value="form.representation"
                                            placeholder="e.g. ff5733 (no #)"
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

                                <a-form-item
                                    v-else
                                    label="Product image"
                                    :validate-status="
                                        validationHasError(
                                            form.errors,
                                            'representation_image',
                                        ) ||
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
                                            'representation_image',
                                        ) ||
                                        validationMessage(
                                            form.errors,
                                            'representation',
                                        ) ||
                                        'JPEG, PNG, WebP or GIF up to 2MB'
                                    "
                                    class="mb-0"
                                >
                                    <a-upload
                                        v-model:file-list="imageFileList"
                                        list-type="picture-card"
                                        :max-count="1"
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        :before-upload="beforeImageUpload"
                                        @remove="onImageRemove"
                                    >
                                        <div v-if="imageFileList.length < 1">
                                            <PlusOutlined />
                                            <div class="mt-2">Upload</div>
                                        </div>
                                    </a-upload>
                                </a-form-item>
                            </section>

                            <div
                                class="mt-2 flex flex-col-reverse gap-2 border-t border-gray-200 pt-6 md:mt-8 md:flex-row md:justify-end"
                            >
                                <Link
                                    class="block w-full md:w-auto"
                                    :href="
                                        hrefWithPreservedLocationId(
                                            getRoute('products.index'),
                                        )
                                    "
                                >
                                    <a-button class="w-full md:w-auto"
                                        >Cancel</a-button
                                    >
                                </Link>
                                <a-button
                                    type="primary"
                                    class="w-full md:w-auto"
                                    :loading="form.processing"
                                    :disabled="productsAtCapacity"
                                    @click="handleSave"
                                >
                                    <template #icon>
                                        <PlusOutlined />
                                    </template>
                                    Create Product
                                </a-button>
                            </div>
                        </a-form>
                    </div>
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
