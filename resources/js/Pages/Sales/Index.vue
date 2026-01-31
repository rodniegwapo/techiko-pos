<script setup>
import { ref, computed, onMounted, onBeforeUnmount, provide } from "vue";
import axios from "axios";

import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayoutV2 from "@/Components/ContentLayoutV2.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ProductTable from "./components/ProductTable.vue";
import CustomerOrder from "./components/CustomerOrder.vue";
import TotalAmountSection from "./components/TotalAmountSection.vue";

import {
    CloseOutlined,
    PlusSquareOutlined,
    MinusSquareOutlined,
} from "@ant-design/icons-vue";
import { message } from "ant-design-vue";

import { usePage, router, Head } from "@inertiajs/vue3";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { watchDebounced } from "@vueuse/core";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute } = useDomainRoutes();

const search = ref("");
const category = ref();
const spinning = ref(false);
const barcodeBuffer = ref("");
const lastKeyTime = ref(0);
const BARCODE_DELAY = 50; // ms

const handleGlobalKeydown = (e) => {
    // If user is typing in an input, we might want to let them type.
    // BUT scanners act as keyboard.
    // If the input is the search box, the scanner will just type into it.
    // If the scanner ends with ENTER, it will trigger the search.
    // However, the user wants "Auto add".
    // If we rely on search, we get search results.
    // If we intercept, we can auto add.

    const tagName = e.target.tagName;
    const isInput = tagName === "INPUT" || tagName === "TEXTAREA";

    // If typing in search box, let it be populated, but capture ENTER to check if it's a barcode scan?
    // Or we can rely on the timing to detect scan?

    // Logic:
    // 1. Detect rapid keystrokes (Scanner).
    // 2. Buffer them.
    // 3. On Enter, if buffer is valid, process scan.

    const now = Date.now();

    if (e.key === "Enter") {
        if (barcodeBuffer.value.length > 0) {
            // Valid scan detected?
            // Prevent default if it's a scan (so it doesn't submit form)
            if (
                now - lastKeyTime.value < BARCODE_DELAY * 10 ||
                barcodeBuffer.value.length > 3
            ) {
                e.preventDefault();
                processScan(barcodeBuffer.value);
            }
            barcodeBuffer.value = "";
        }
        return;
    }

    // Filter out special keys
    if (e.key.length > 1) return;

    if (now - lastKeyTime.value > BARCODE_DELAY) {
        // Too slow, probably manual typing. Reset buffer unless it's the start.
        // If it's the very first char, maybe start buffering?
        // But if typing fast manually?

        // Simple approach: Always buffer, but clear if too slow?
        // No, that breaks manual typing if we listen globally.

        // If focused on input, let default behavior happen.
        // But also capture for buffer to check for scan?

        // If we are NOT in an input, capture.
        if (!isInput) {
            barcodeBuffer.value = ""; // Reset
        }
    }

    if (!isInput) {
        barcodeBuffer.value += e.key;
        lastKeyTime.value = now;
    } else {
        // If in input, we also capture to buffer to detect scan-paste?
        barcodeBuffer.value += e.key;
        lastKeyTime.value = now;
    }
};

onMounted(() => {
    window.addEventListener("keydown", handleGlobalKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", handleGlobalKeydown);
});

const processScan = (code) => {
    search.value = code;
    // Call getProducts and then try to add to cart
    handleScanAndAdd();
};

const handleScanAndAdd = async () => {
    loading.value = true;
    const hide = message.loading("Processing scan...", 0);
    try {
        const items = await axios.get(
            getRoute("sales.products", {
                category: category.value,
                search: search.value,
            }),
        );
        const results = items.data.data;

        products.value = results;

        if (results.length === 1) {
            const product = results[0];
            await addToCart(product);
            message.success(`Added ${product.name} to cart`);
            search.value = "";
            getProducts();
        } else if (results.length > 1) {
            const exactMatch = results.find(
                (p) => p.code === search.value || p.barcode === search.value,
            );

            if (exactMatch) {
                await addToCart(exactMatch);
                message.success(`Added ${exactMatch.name} to cart`);
                search.value = "";
                getProducts();
            } else {
                message.warning("Multiple products found, please select one.");
            }
        } else {
            message.error(`Product not found: ${search.value}`);
        }
    } catch (e) {
        console.error("Scan error:", e);
        message.error("Error processing scan");
    } finally {
        hide();
        loading.value = false;
    }
};

const addToCart = async (product) => {
    try {
        loading.value = true;
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.add", { user: userId });

        await axios.post(route, {
            product_id: product.id,
            quantity: 1,
        });

        // Refresh cart
        await loadCurrentPendingSale();

        // Feedback?
    } catch (error) {
        console.error("Failed to add item to cart:", error);
    } finally {
        loading.value = false;
    }
};

// Direct database integration - no composable needed
const orderId = ref(null);
const orders = ref([]);
const currentSale = ref(null);
const orderDiscountAmount = ref(0);
const orderDiscountId = ref("");
const isLoadingCart = ref(false);
const discountOptions = ref({
    product_discount_options: [],
    promotional_discount_options: [],
    mandatory_discount_options: [],
});

// Direct API functions
const loadCurrentPendingSale = async () => {
    isLoadingCart.value = true;

    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.current-pending", { user: userId });
        const response = await axios.get(route);

        if (response.data.sale) {
            const { sale, items, discounts, totals, discount_options } =
                response.data;

            // Set the orderId from the found sale
            orderId.value = sale.id;

            // Store the full sale object for the modal
            currentSale.value = sale;

            // Store discount options for modals
            if (discount_options) {
                discountOptions.value = discount_options;
            }

            // Transform database response to match frontend expectations
            orders.value = transformCartItems(items);
            orderDiscountAmount.value =
                parseFloat(totals?.discount_amount) || 0;
            orderDiscountId.value =
                discounts?.map((d) => d.discount_id).join(",") || "";

            // Log product-level discounts for debugging
            const productsWithDiscounts = orders.value.filter(
                (order) => order.discount_id || order.discount_amount > 0,
            );
        } else {
            orderId.value = null;
            orders.value = [];
            orderDiscountAmount.value = 0;
            orderDiscountId.value = "";
        }
    } catch (error) {
        orderId.value = null;
        orders.value = [];
        orderDiscountAmount.value = 0;
        orderDiscountId.value = "";
    } finally {
        isLoadingCart.value = false;
    }
};

const createDraft = async () => {
    console.log("createDraft called - now handled by user-specific routes");
    // This function is no longer needed as user-specific routes handle sale creation automatically
    return orderId.value;
};

// Utility function to transform cart items
const transformCartItems = (items) => {
    if (!items || !Array.isArray(items)) {
        console.warn("transformCartItems received invalid items:", items);
        return [];
    }

    return items.map((item) => {
        const productName = item.product?.name || "Unknown Product";

        return {
            id: item.product_id,
            name: productName,
            price: item.unit_price,
            quantity: item.quantity,
            subtotal: item.unit_price * item.quantity,
            discount_id: item.discount_id,
            discount_type: item.discount_type,
            discount: item.discount,
            discount_amount: item.discount_amount,
            discounts: item.discounts || [], // Include the discounts relationship
        };
    });
};

// Customer state management
const selectedCustomer = ref(null);

// Handle customer changes from CustomerOrder component
const handleCustomerChanged = async (customer) => {
    selectedCustomer.value = customer;

    // Only load pending sale when customer is selected
    if (customer) {
        try {
            await loadCurrentPendingSale();

            // If no pending sale found, create a new draft
            if (!orderId.value) {
                await createDraft();
            }

            if (!orderId.value) {
                throw new Error("No order ID available");
            }

            const route = getRoute("sales.sales.assignCustomer", {
                sale: orderId.value,
            });

            await axios.post(route, {
                customer_id: customer?.id || null,
            });
        } catch (error) {
            console.error("Error handling customer selection:", error);
        }
    }
};

// Filters setup

// FilterDropdown configuration
const filtersConfig = [
    {
        key: "category",
        label: "Category",
        type: "select",
        options: (page.props?.categories ?? []).map((item) => ({
            label: item.name,
            value: item.name,
        })),
    },
];

const products = ref([]);
const loading = ref(false);
onMounted(async () => {
    // Load products first
    getProducts();

    // Auto-load current user's pending sale on page reload
    try {
        await loadCurrentPendingSale();
    } catch (error) {
        console.error("Failed to auto-load pending sale:", error);
        // Continue normally - user can still create new orders
    }
});

const getProducts = async () => {
    loading.value = true;
    const items = await axios.get(
        getRoute("sales.products", {
            category: category.value,
            search: search.value,
        }),
    );
    products.value = items.data.data;
    loading.value = false;
};
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems: getProducts,
    configs: [
        {
            key: "category",
            ref: category,
            getLabel: toLabel(
                computed(() =>
                    (page.props?.categories ?? []).map((item) => ({
                        label: item.name,
                        value: item.name,
                    })),
                ),
            ),
        },
    ],
});

watchDebounced(search, getProducts, { debounce: 300 });
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Sales" />
        <ContentHeader title="Sales" />

        <ContentLayoutV2 title="Create Transaction">
            <template #filters>
                <a-input-search
                    v-model:value="search"
                    placeholder="Search Product"
                    class="min-w-[100px] max-w-[300px]"
                />
                <RefreshButton :loading="loading" @click="getProducts" />
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>
            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="
                        () =>
                            Object.keys(filters).forEach(
                                (k) => (filters[k] = null),
                            )
                    "
                    :always-show="true"
                />
            </template>

            <template #table>
                <ProductTable
                    :products="products"
                    :loading="loading"
                    :orders="orders"
                    :orderId="orderId"
                    @cart-updated="loadCurrentPendingSale"
                />
            </template>
            <template #right-side-content>
                <customer-order
                    @customer-changed="handleCustomerChanged"
                    @discount-applied="loadCurrentPendingSale"
                    @cart-updated="loadCurrentPendingSale"
                    :loading="isLoadingCart"
                    :orders="orders"
                    :orderId="orderId"
                    :orderDiscountAmount="orderDiscountAmount"
                    :orderDiscountId="orderDiscountId"
                    :discountOptions="discountOptions"
                />
            </template>
        </ContentLayoutV2>
        <template #content-footer>
            <total-amount-section
                :selected-customer="selectedCustomer"
                :orders="orders"
                :currentSale="currentSale"
                :orderDiscountAmount="orderDiscountAmount"
                :orderDiscountId="orderDiscountId"
                :orderId="orderId"
                :discountOptions="discountOptions"
                @discount-applied="loadCurrentPendingSale"
                @cart-updated="loadCurrentPendingSale"
            />
        </template>
    </AuthenticatedLayout>
</template>
