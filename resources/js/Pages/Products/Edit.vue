<script setup>
import { computed, onMounted } from "vue";
import { Link, useForm, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { message } from "ant-design-vue";

const page = usePage();
const { getRoute } = useDomainRoutes();

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

import { useBarcodeScanner } from "@/Composables/useBarcodeScanner";

const handleUpdate = () => {
    form.put(getRoute("products.update", { product: props.product.id }), {
        onSuccess: () => {
            message.success("Product updated successfully");
        },
        onError: () => {
            message.error("Failed to update product");
        },
    });
};

useBarcodeScanner((code) => {
    form.barcode = code;
    message.success("Barcode Scanned: " + code);
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Edit Product" />
        <ContentHeader class="mb-8" title="Edit Product" />

        <ContentLayout title="Edit Product">
            <template #filters>
                <Link :href="getRoute('products.index')">
                    <a-button>Back to Products</a-button>
                </Link>
            </template>

            <template #table>
                <div class="max-w-2xl mx-auto p-6 bg-white rounded-lg shadow">
                    <a-form layout="vertical">
                        <!-- Product Name -->
                        <a-form-item
                            label="Product Name"
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name || ''"
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
                            :validate-status="form.errors.domain ? 'error' : ''"
                            :help="form.errors.domain || ''"
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
                            label="Category"
                            :validate-status="
                                form.errors.category_id ? 'error' : ''
                            "
                            :help="form.errors.category_id || ''"
                        >
                            <a-select
                                v-model:value="form.category_id"
                                :options="categoriesOption"
                                placeholder="Select category"
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

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Cost -->
                            <a-form-item
                                label="Cost"
                                :validate-status="
                                    form.errors.cost ? 'error' : ''
                                "
                                :help="form.errors.cost || ''"
                            >
                                <a-input-number
                                    v-model:value="form.cost"
                                    placeholder="Enter cost"
                                    :min="0"
                                    :precision="2"
                                    style="width: 100%"
                                    size="large"
                                />
                            </a-form-item>

                            <!-- Price -->
                            <a-form-item
                                label="Price"
                                :validate-status="
                                    form.errors.price ? 'error' : ''
                                "
                                :help="form.errors.price || ''"
                            >
                                <a-input-number
                                    v-model:value="form.price"
                                    placeholder="Enter price"
                                    :min="0"
                                    :precision="2"
                                    style="width: 100%"
                                    size="large"
                                />
                            </a-form-item>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- SKU -->
                            <a-form-item
                                label="SKU"
                                :validate-status="
                                    form.errors.SKU ? 'error' : ''
                                "
                                :help="form.errors.SKU || ''"
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
                                :validate-status="
                                    form.errors.barcode ? 'error' : ''
                                "
                                :help="form.errors.barcode || ''"
                            >
                                <a-input
                                    v-model:value="form.barcode"
                                    placeholder="Enter barcode"
                                    size="large"
                                />
                            </a-form-item>
                        </div>

                        <!-- Sold Type -->
                        <a-form-item
                            label="Sold Type"
                            :validate-status="
                                form.errors.sold_type ? 'error' : ''
                            "
                            :help="form.errors.sold_type || ''"
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

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Representation Type -->
                            <a-form-item
                                label="Reperesentation Type"
                                :validate-status="
                                    form.errors.representation_type
                                        ? 'error'
                                        : ''
                                "
                                :help="form.errors.representation_type || ''"
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

                            <!-- Representation -->
                            <a-form-item
                                label="Representation"
                                :validate-status="
                                    form.errors.representation ? 'error' : ''
                                "
                                :help="form.errors.representation || ''"
                            >
                                <a-input
                                    v-model:value="form.representation"
                                    placeholder="Enter representation (e.g., hex color code)"
                                    size="large"
                                />
                            </a-form-item>
                        </div>

                        <div class="flex justify-end gap-2 mt-4">
                            <Link :href="getRoute('products.index')">
                                <a-button>Cancel</a-button>
                            </Link>
                            <a-button
                                type="primary"
                                :loading="form.processing"
                                @click="handleUpdate"
                                >Update Product</a-button
                            >
                        </div>
                    </a-form>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
