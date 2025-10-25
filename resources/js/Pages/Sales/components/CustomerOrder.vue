<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import ApplyProductDiscountModal from "./ApplyProductDiscountModal.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import CustomerLoyaltyCard from "@/Components/Loyalty/CustomerLoyaltyCard.vue";
import {
    ref,
    inject,
    computed,
    createVNode,
    watch,
    toRefs,
    nextTick,
} from "vue";
import { IconArmchair, IconUsers } from "@tabler/icons-vue";
import {
    CloseOutlined,
    PlusSquareOutlined,
    MinusSquareOutlined,
    ExclamationCircleOutlined,
    PlusOutlined,
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import axios from "axios";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import { useDebounceFn } from "@vueuse/core";

const { formData, errors } = useGlobalVariables();
const { getRoute } = useDomainRoutes();
const page = usePage();

// Loading states for each product to prevent multiple rapid clicks
const loadingStates = ref({});

// Optimistic UI tracking for instant quantity updates
const optimisticQuantities = ref({});

// Track which quantities are being edited
const editingQuantity = ref({});

// Modal state for quantity input
const quantityModalVisible = ref(false);
const selectedOrder = ref(null);
const tempQuantity = ref(0);
// Props for direct data passing
const props = defineProps({
    orders: { type: Array, default: () => [] },
    orderId: { type: [String, Number], default: null },
    orderDiscountAmount: { type: Number, default: 0 },
    orderDiscountId: { type: String, default: "" },
    discountOptions: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
});

const {
    orders,
    orderId,
    orderDiscountAmount,
    orderDiscountId,
    discountOptions,
    loading,
} = toRefs(props);

// Computed values
const totalAmount = computed(() => {
    return orders.value.reduce((sum, order) => {
        const price = parseFloat(order.price) || 0;
        const quantity = parseInt(order.quantity) || 0;
        const subtotal = !isNaN(price * quantity)
            ? price * quantity
            : quantity * price;
        return sum + subtotal;
    }, 0);
});

// Using formattedTotal from useHelpers composable

// Direct API functions
const handleAddOrder = async (product) => {
    if (!orderId.value) {
        console.error("No active order - cannot add item");
        return;
    }

    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.add", { user: userId });
        await axios.post(route, {
            product_id: product.id,
            quantity: 1,
        });

        // Emit event to parent to refresh cart data
        emit("cart-updated");
    } catch (error) {
        console.error("Failed to add item:", error);
    }
};

const handleSubtractOrder = async (product) => {
    if (!orderId.value) {
        console.error("No active order - cannot subtract item");
        return;
    }

    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.update-quantity", {
            user: userId,
        });
        await axios.patch(route, {
            product_id: product.id,
            quantity: Math.max(0, product.quantity - 1),
        });

        // Emit event to parent to refresh cart data
        emit("cart-updated");
    } catch (error) {
        console.error("Failed to subtract item:", error);
    }
};

const handleUpdateQuantity = async (product, quantity) => {
    if (!orderId.value) {
        console.error("No active order - cannot update quantity");
        return;
    }

    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.update-quantity", {
            user: userId,
        });
        await axios.patch(route, {
            product_id: product.id,
            quantity: quantity,
        });

        // Emit event to parent to refresh cart data
        emit("cart-updated");
    } catch (error) {
        console.error("Failed to update quantity:", error);
    }
};

const removeOrder = async (product) => {
    if (!orderId.value) {
        console.error("No active order - cannot remove item");
        return;
    }

    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.remove", { user: userId });
        await axios.delete(route, {
            data: { product_id: product.id },
        });

        // Emit event to parent to refresh cart data
        emit("cart-updated");
    } catch (error) {
        console.error("Failed to remove item:", error);
    }
};

// Optimistic UI click handlers with direct API calls
const onAddClick = async (product) => {
    // Prevent multiple rapid clicks
    if (loadingStates.value[product.id]) return;

    // Set loading state
    loadingStates.value[product.id] = true;

    // Immediately update UI optimistically
    if (!optimisticQuantities.value[product.id]) {
        optimisticQuantities.value[product.id] = product.quantity;
    }
    optimisticQuantities.value[product.id] += 1;

    try {
        // Call the API directly
        await handleAddOrder(product);
        // Clear optimistic state after successful API call
        delete optimisticQuantities.value[product.id];
    } catch (error) {
        // Revert optimistic state on error
        optimisticQuantities.value[product.id] = product.quantity;
    } finally {
        // Clear loading state
        loadingStates.value[product.id] = false;
    }
};

const onSubtractClick = async (product) => {
    // Prevent multiple rapid clicks
    if (loadingStates.value[product.id]) return;

    // Set loading state
    loadingStates.value[product.id] = true;

    // Immediately update UI optimistically
    if (!optimisticQuantities.value[product.id]) {
        optimisticQuantities.value[product.id] = product.quantity;
    }
    optimisticQuantities.value[product.id] = Math.max(
        0,
        optimisticQuantities.value[product.id] - 1
    );

    try {
        // Call the API directly
        await handleSubtractOrder(product);
        // Clear optimistic state after successful API call
        delete optimisticQuantities.value[product.id];
    } catch (error) {
        // Revert optimistic state on error
        optimisticQuantities.value[product.id] = product.quantity;
    } finally {
        // Clear loading state
        loadingStates.value[product.id] = false;
    }
};

// Helper function to get display quantity (optimistic or actual)
const getDisplayQuantity = (order) => {
    return optimisticQuantities.value[order.id] ?? order.quantity;
};

// Open quantity modal
const toggleQuantityEdit = (order) => {
    if (loadingStates.value[order.id]) return;

    selectedOrder.value = order;
    tempQuantity.value = getDisplayQuantity(order);
    quantityModalVisible.value = true;

    // Focus input after modal opens
    nextTick(() => {
        const input = document.querySelector(".ant-input-number-input");
        if (input) {
            input.focus();
            input.select();
        }
    });
};

// Finish quantity editing
const finishQuantityEdit = (order) => {
    editingQuantity.value[order.id] = false;
};

// Save quantity from modal
const saveQuantity = async () => {
    if (!selectedOrder.value) return;

    const newQuantity = parseInt(tempQuantity.value);
    if (isNaN(newQuantity) || newQuantity < 0) {
        notification.error({
            message: "Invalid quantity",
            description: "Please enter a valid number",
        });
        return;
    }

    // Set loading state
    loadingStates.value[selectedOrder.value.id] = true;

    try {
        // Call the function directly
        await handleUpdateQuantity(selectedOrder.value, newQuantity);

        // Update optimistic state
        optimisticQuantities.value[selectedOrder.value.id] = newQuantity;

        // Close modal
        quantityModalVisible.value = false;

        notification.success({
            message: "Quantity updated",
            description: `Quantity updated to ${newQuantity}`,
        });
    } catch (error) {
        // Revert optimistic state on error
        optimisticQuantities.value[selectedOrder.value.id] =
            selectedOrder.value.quantity;

        notification.error({
            message: "Update failed",
            description: "Failed to update quantity. Please try again.",
        });
    } finally {
        // Clear loading state
        loadingStates.value[selectedOrder.value.id] = false;
    }
};

// Cancel quantity modal
const cancelQuantity = () => {
    quantityModalVisible.value = false;
    selectedOrder.value = null;
    tempQuantity.value = 0;
};

const finalizeOrder = async () => {
    if (!orderId.value) {
        notification.error({
            message: "No active order",
            description: "Please create an order first",
        });
        return;
    }

    try {
        const response = await axios.post(
            getRoute("payment.store", { sale: orderId.value })
        );
        notification.success({
            message: "Order finalized successfully",
            description: "The order has been completed",
        });
    } catch (error) {
        console.error("Failed to finalize order:", error);
        notification.error({
            message: "Failed to finalize order",
            description: error.response?.data?.message || "An error occurred",
        });
    }
};

const { formattedPercent, formattedTotal } = useHelpers();

const formFields = [
    { key: "amount", label: "Amount", type: "text", disabled: true },
    { key: "sale_item", label: "Item", type: "text", disabled: true },
    { key: "pin_code", label: "Enter Pin", type: "password" },
    {
        key: "reason",
        label: "Reason",
        type: "textarea",
    },
];

const openvoidModal = ref(false);

const showVoidItem = async (product) => {
    errors.value = {};
    openvoidModal.value = true;
    formData.value = {
        ...product,
        sale_item: product.name,
        amount: product.price,
        product_id: product.id,
    };
};

const isLoadingVoid = ref(false);
const handleSubmitVoid = async () => {
    try {
        isLoadingVoid.value = true;
        await axios.post(
            route("sales.items.void", {
                sale: orderId.value,
            }),
            formData.value
        );
        removeOrder(formData.value);
        openvoidModal.value = false;
        clearForm();
        notification["success"]({
            message: "Success",
            description: "The item was successfully voided.",
        });
    } catch ({ response }) {
        errors.value = response?.data?.errors;
    } finally {
        isLoadingVoid.value = false;
    }
};

const clearForm = () => {
    formData.value = {
        amount: "",
        sale_item: "",
        pin_code: "",
        reason: "",
        product_id: null,
    };
};

const currentProduct = ref({});
const openApplyDiscountModal = ref(false);
const handleShowProductDiscountModal = (product) => {
    formData.value = {
        discount: product.discounts?.[0]?.id || null,
    };
    currentProduct.value = product;
    openApplyDiscountModal.value = true;
};

const isLoyalCustomer = ref(false);
const customer = ref("");

// Customer loyalty state
const selectedCustomer = ref(null);
const customerSearchQuery = ref("");
const customerOptions = ref([]);
const searchingCustomers = ref(false);
const showAddCustomerModal = ref(false);
const showCustomerDetailsModal = ref(false);
const addingCustomer = ref(false);

// New customer form
const newCustomerForm = ref({
    name: "",
    phone: "",
    email: "",
    date_of_birth: null,
});

const openOrderDicountModal = ref(false);

const showDiscountOrder = async () => {
    // Check if there's an active order/draft OR if there are items in the cart
    // (orderId might be null briefly while draft is being created)
    if (!orderId.value && orders.value.length === 0) return;

    try {
        // Load current discounts from database instead of localStorage
        const response = await axios.get("/api/sales/discounts/current");
        const { regular_discounts, mandatory_discounts } = response.data;

        // Convert database discounts to option objects for the select components
        const regularDiscountOptions = regular_discounts.map((discount) => ({
            label: `${discount.name} (${
                discount.type === "percentage"
                    ? discount.value + "%"
                    : "₱" + discount.value
            })`,
            value: discount.id,
            amount: discount.value,
            type: discount.type,
        }));

        // Get the first active mandatory discount
        const mandatoryDiscountOption =
            mandatory_discounts.length > 0
                ? {
                      label: `${mandatory_discounts[0].name} (${
                          mandatory_discounts[0].type === "percentage"
                              ? mandatory_discounts[0].value + "%"
                              : "₱" + mandatory_discounts[0].value
                      })`,
                      value: mandatory_discounts[0].id,
                      amount: mandatory_discounts[0].value,
                      type: mandatory_discounts[0].type,
                  }
                : null;

        formData.value = {
            orderDiscount: regularDiscountOptions,
            mandatoryDiscount: mandatoryDiscountOption,
        };
        openOrderDicountModal.value = true;
    } catch (error) {
        console.error("Failed to load discounts:", error);
        notification.error({
            message: "Error",
            description: "Failed to load discount options",
        });
    }
};

const cardClass =
    "w-1/2 border shadow text-center flex justify-center rounded-lg items-center gap-2 p-2 hover:bg-blue-400 hover:text-white  cursor-pointer";

const showPayment = ref(false);

// Customer search with debounce
const handleCustomerSearch = useDebounceFn(async (query) => {
    console.log("Searching for:", query); // Debug log

    if (!query || query.length < 2) {
        customerOptions.value = [];
        return;
    }

    searchingCustomers.value = true;

    try {
        const response = await axios.get("/api/customers/search", {
            params: { q: query },
        });

        console.log("Search results:", response.data); // Debug log

        customerOptions.value = response.data.map((customer) => ({
            value: customer.id,
            label: customer.display_text,
            customer: customer,
        }));
    } catch (error) {
        console.error("Customer search error:", error);
        notification.error({
            message: "Search Error",
            description: "Failed to search customers. Please try again.",
        });
    } finally {
        searchingCustomers.value = false;
    }
}, 300);

// Handle customer selection
const handleCustomerSelect = (customerId) => {
    console.log("Customer selected:", customerId); // Debug log

    const option = customerOptions.value.find(
        (opt) => opt.value === customerId
    );
    if (option) {
        selectedCustomer.value = option.customer;
        customerSearchQuery.value = "";
        customerOptions.value = [];

        console.log("Selected customer:", option.customer); // Debug log

        notification.success({
            message: "Customer Selected",
            description: `${option.customer.name} (${option.customer.tier_info.name} tier) selected`,
            duration: 2,
        });
    } else {
        console.error("Customer option not found for ID:", customerId);
    }
};

// Handle customer type change
const handleCustomerTypeChange = (isLoyal) => {
    if (!isLoyal) {
        clearCustomer();
    }
};

// Clear selected customer
const clearCustomer = () => {
    selectedCustomer.value = null;
    customerSearchQuery.value = "";
    customerOptions.value = [];
    // Emit the change to parent
    emit("customerChanged", null);
};

// Show customer details
const showCustomerDetails = () => {
    showCustomerDetailsModal.value = true;
};

// Add new customer
const handleAddCustomer = async () => {
    if (!newCustomerForm.value.name) {
        notification.error({
            message: "Validation Error",
            description: "Customer name is required",
        });
        return;
    }

    addingCustomer.value = true;

    try {
        const response = await axios.post(
            "/api/customers",
            newCustomerForm.value
        );

        selectedCustomer.value = response.data.customer;
        showAddCustomerModal.value = false;

        // Reset form
        newCustomerForm.value = {
            name: "",
            phone: "",
            email: "",
            date_of_birth: null,
        };

        notification.success({
            message: "Customer Added",
            description: `${response.data.customer.name} has been added and selected`,
        });
    } catch (error) {
        notification.error({
            message: "Error",
            description:
                error.response?.data?.message || "Failed to add customer",
        });
    } finally {
        addingCustomer.value = false;
    }
};

// Emit customer changes to parent
const emit = defineEmits([
    "customerChanged",
    "discount-applied",
    "cart-updated",
]);

// Reset optimistic quantities when orders data changes (cart refresh)
watch(
    orders,
    (newOrders) => {
        // Clear optimistic state when cart data is refreshed
        optimisticQuantities.value = {};
        // Clear loading states when cart data is refreshed
        loadingStates.value = {};
        // Clear editing states when cart data is refreshed
        editingQuantity.value = {};
        // Close modal if open
        quantityModalVisible.value = false;
        selectedOrder.value = null;
        tempQuantity.value = 0;
    },
    { deep: true }
);

// Watch for customer changes and emit to parent
watch(
    selectedCustomer,
    (newCustomer) => {
        emit("customerChanged", newCustomer);
    },
    { immediate: true }
);

// Export customer data for parent component
defineExpose({
    selectedCustomer,
    isLoyalCustomer,
});
</script>

<template>
    <div class="flex items-center justify-between">
        <div class="font-semibold text-lg">Current Order</div>
        <a-switch
            v-model:checked="isLoyalCustomer"
            checked-children="Loyal"
            un-checked-children="Walk-in"
            @change="handleCustomerTypeChange"
        />
    </div>

    <!-- Customer Search/Display -->
    <div class="mt-1">
        <div v-if="isLoyalCustomer" class="space-y-2 max-h-52 overflow-y-auto">
            <!-- Search Instructions -->
            <div
                v-if="!selectedCustomer && customerSearchQuery.length < 2"
                class="text-xs text-gray-500 mb-1"
            >
                💡 Type at least 2 characters to search for existing customers
            </div>

            <!-- Customer Search -->
            <a-auto-complete
                v-if="!selectedCustomer"
                v-model:value="customerSearchQuery"
                :options="customerOptions"
                placeholder="Search customer by name, phone, or email (min 2 chars)"
                :loading="searchingCustomers"
                @search="handleCustomerSearch"
                @select="handleCustomerSelect"
                @clear="
                    () => {
                        customerOptions = [];
                    }
                "
                allow-clear
                class="w-full"
                :filter-option="false"
            >
                <template #option="{ value, label, customer }">
                    <div
                        class="flex justify-between items-center py-2 px-1 hover:bg-gray-50 rounded transition-colors"
                    >
                        <div class="flex-1">
                            <div class="font-medium text-sm">
                                {{ customer.name }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{
                                    customer.phone ||
                                    customer.email ||
                                    "No contact info"
                                }}
                            </div>
                        </div>
                        <div class="text-right ml-2">
                            <div
                                class="text-xs font-medium px-2 py-0.5 rounded-full text-white"
                                :style="{
                                    backgroundColor: customer.tier_info.color,
                                }"
                            >
                                {{ customer.tier_info.name }}
                            </div>
                            <div class="text-xs text-purple-600 mt-0.5">
                                {{
                                    customer.loyalty_points?.toLocaleString() ||
                                    0
                                }}
                                pts
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Loading state -->
                <template #notFoundContent v-if="searchingCustomers">
                    <div class="text-center py-2 text-gray-500">
                        <a-spin size="small" /> Searching...
                    </div>
                </template>

                <!-- Empty state -->
                <template
                    #notFoundContent
                    v-else-if="
                        customerSearchQuery &&
                        customerSearchQuery.length >= 2 &&
                        customerOptions.length === 0
                    "
                >
                    <div class="text-center py-2 text-gray-500">
                        No customers found.
                        <a-button
                            type="link"
                            size="small"
                            @click="showAddCustomerModal = true"
                        >
                            Add new customer
                        </a-button>
                    </div>
                </template>
            </a-auto-complete>

            <!-- Selected Customer Card -->
            <CustomerLoyaltyCard
                v-if="selectedCustomer"
                :customer="selectedCustomer"
                :total-amount="totalAmount"
                :show-points-preview="true"
                @view-details="showCustomerDetails"
                @clear-customer="clearCustomer"
            />

            <!-- Quick Add Customer -->
            <div class="flex gap-2">
                <a-button
                    type="primary"
                    size="small"
                    @click="showAddCustomerModal = true"
                >
                    <div class="flex items-center gap-2">
                        <plus-outlined />
                        <span class="text-xs"> Add New Customer</span>
                    </div>
                </a-button>
            </div>
        </div>

        <!-- Walk-in Customer Display -->
        <a-input v-else value="Walk-in Customer" disabled />
    </div>
    <!-- <div class="mt-2">
    <div class="font-semibold">Quick Discounts</div>
    <div class="flex items-center gap-2 mt-1">
      <div :class="cardClass">
        <div><IconArmchair size="20" /></div>
        <div class="text-left text-xs">
          <div>PWD</div>
          <div>20% Off</div>
        </div>
      </div>
      <div :class="cardClass">
        <div><IconUsers size="20" /></div>
        <div class="text-left text-xs">
          <div>Senior</div>
          <div>20% Off</div>
        </div>
      </div>
    </div>
  </div> -->

    <div class="relative">
        <!-- Transition wrapper -->
        <Transition name="slide-x" mode="out-in">
            <!-- 🟥 ORDER SUMMARY PAGE -->
            <div v-if="!showPayment" key="order">
                <div
                    :class="[
                        'scrollable-orders relative flex flex-col gap-2 mt-4 overflow-auto overflow-x-hidden transition-all duration-300',
                        {
                            'h-[calc(100vh-430px)]': !isLoyalCustomer,
                            'h-[calc(100vh-480px)]':
                                isLoyalCustomer && !selectedCustomer,
                            'h-[calc(100vh-570px)]':
                                isLoyalCustomer &&
                                selectedCustomer &&
                                totalAmount <= 0,
                            'h-[calc(100vh-540px)]':
                                isLoyalCustomer &&
                                selectedCustomer &&
                                totalAmount > 0,
                        },
                    ]"
                >
                    <div
                        v-if="orders.length == 0"
                        class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
                    >
                        No Order
                    </div>

                    <div v-else class="flex flex-col gap-2">
                        <div
                            v-for="(order, index) in orders"
                            :key="index"
                            class="flex justify-between items-center border relative px-4 rounded-lg bg-white hover:shadow cursor-pointer"
                            @click="handleShowProductDiscountModal(order)"
                        >
                            <div class="flex flex-col gap-1 py-1">
                                <div class="text-sm font-semibold">
                                    {{ order.name }}
                                </div>

                                <div
                                    class="flex items-center gap-2"
                                    @click.stop
                                >
                                    <a-tooltip title="Remove item">
                                        <a-button
                                            type="text"
                                            size="small"
                                            :disabled="
                                                loadingStates[order.id] ||
                                                getDisplayQuantity(order) <= 0
                                            "
                                            @click.stop="onSubtractClick(order)"
                                            class="quantity-button minus"
                                        >
                                            <template #icon>
                                                <MinusSquareOutlined />
                                            </template>
                                        </a-button>
                                    </a-tooltip>

                                    <!-- Quantity Display with modal input -->
                                    <div
                                        class="quantity-display cursor-pointer"
                                        @click="toggleQuantityEdit(order)"
                                    >
                                        <span
                                            v-if="loadingStates[order.id]"
                                            class="quantity-loading text-xs"
                                        >
                                            <a-spin size="small" />
                                        </span>
                                        <span
                                            v-else
                                            class="text-sm font-semibold text-gray-700"
                                        >
                                            {{ getDisplayQuantity(order) }}
                                        </span>
                                    </div>

                                    <a-tooltip title="Add item">
                                        <a-button
                                            type="text"
                                            size="small"
                                            :disabled="loadingStates[order.id]"
                                            @click.stop="onAddClick(order)"
                                            class="quantity-button plus"
                                        >
                                            <template #icon>
                                                <PlusSquareOutlined />
                                            </template>
                                        </a-button>
                                    </a-tooltip>
                                </div>
                                <div class="text-[11px]" @click.stop>
                                    {{ order.price }} x
                                    <span
                                        v-if="loadingStates[order.id]"
                                        class="animate-pulse bg-gray-200 rounded px-1"
                                    >
                                        {{ getDisplayQuantity(order) }}
                                    </span>
                                    <span v-else>{{
                                        getDisplayQuantity(order)
                                    }}</span>
                                </div>
                            </div>

                            <div
                                class="text-right flex flex-col py-1 items-end gap-1"
                            >
                                <a-tooltip title="Remove item from order">
                                    <a-button
                                        type="text"
                                        size="small"
                                        @click.stop="showVoidItem(order)"
                                        class="p-1 h-auto border-0 text-red-600 hover:text-red-700 hover:bg-red-50"
                                    >
                                        <template #icon>
                                            <CloseOutlined />
                                        </template>
                                    </a-button>
                                </a-tooltip>

                                <div
                                    class="text-xs"
                                    v-if="
                                        order.discount &&
                                        parseFloat(order.discount) > 0
                                    "
                                >
                                    <div
                                        class="text-gray-600 border-b px-2"
                                        v-if="
                                            order.discounts &&
                                            order.discounts.length > 0 &&
                                            (order.discounts[0].type ===
                                                'percentage' ||
                                                order.discounts[0].type ===
                                                    'percent')
                                        "
                                    >
                                        Disc:
                                        {{
                                            parseFloat(
                                                order.discounts[0].value
                                            )
                                        }}% -
                                        {{
                                            formattedTotal(
                                                parseFloat(order.discount) || 0
                                            )
                                        }}
                                    </div>
                                    <div
                                        class="text-gray-600 border-b px-2"
                                        v-else-if="
                                            order.discounts &&
                                            order.discounts.length > 0 &&
                                            order.discounts[0].type === 'amount'
                                        "
                                    >
                                        Disc: ₱{{
                                            parseFloat(
                                                order.discounts[0].value
                                            ).toFixed(2)
                                        }}
                                        -
                                        {{
                                            formattedTotal(
                                                parseFloat(order.discount) || 0
                                            )
                                        }}
                                    </div>
                                    <div
                                        class="text-gray-600 border-b px-2"
                                        v-else
                                    >
                                        Disc: -
                                        {{
                                            formattedTotal(
                                                parseFloat(order.discount) || 0
                                            )
                                        }}
                                    </div>
                                </div>
                                <div
                                    class="text-xs text-gray-600 line-through invisible"
                                    v-else
                                >
                                    Discount
                                </div>

                                <div
                                    class="text-md font-semibold text-green-700"
                                    v-if="order.discount"
                                >
                                    {{
                                        formattedTotal(
                                            parseFloat(order.subtotal) || 0
                                        )
                                    }}
                                </div>
                                <div
                                    v-else
                                    class="text-md font-semibold text-green-700"
                                >
                                    {{
                                        formattedTotal(
                                            (parseFloat(order.price) || 0) *
                                                (parseFloat(order.quantity) ||
                                                    0)
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </div>

    <a-modal
        v-model:visible="openvoidModal"
        title="Void Product"
        @cancel="openvoidModal = false"
        :maskClosable="false"
        width="450px"
    >
        <vertical-form
            v-model="formData"
            :fields="formFields"
            :errors="errors"
        />
        <template #footer>
            <a-button @click="openvoidModal = false">Cancel</a-button>

            <primary-button :loading="loading" @click="handleSubmitVoid"
                >Submit
            </primary-button>
        </template>
    </a-modal>

    <apply-product-discount-modal
        :openModal="openApplyDiscountModal"
        :product="currentProduct"
        :orderId="orderId"
        :orders="orders"
        :discountOptions="discountOptions"
        @close="openApplyDiscountModal = false"
        @discount-applied="emit('discount-applied')"
    />

    <apply-order-discount-modal
        :openModal="openOrderDicountModal"
        :product="currentProduct"
        :orderId="orderId"
        :orders="orders"
        :orderDiscountAmount="orderDiscountAmount"
        :orderDiscountId="orderDiscountId"
        :discountOptions="discountOptions"
        @close="openOrderDicountModal = false"
    />

    <!-- Add Customer Modal -->
    <a-modal
        v-model:visible="showAddCustomerModal"
        title="Add New Customer"
        @ok="handleAddCustomer"
        okText="Add Customer"
        @cancel="showAddCustomerModal = false"
        :confirm-loading="addingCustomer"
    >
        <a-form :model="newCustomerForm" layout="vertical">
            <a-form-item label="Name" required>
                <a-input
                    v-model:value="newCustomerForm.name"
                    placeholder="Customer name"
                />
            </a-form-item>
            <a-form-item label="Phone">
                <a-input
                    v-model:value="newCustomerForm.phone"
                    placeholder="Phone number"
                />
            </a-form-item>
            <a-form-item label="Email">
                <a-input
                    v-model:value="newCustomerForm.email"
                    placeholder="Email address"
                />
            </a-form-item>
            <a-form-item label="Date of Birth">
                <a-date-picker
                    v-model:value="newCustomerForm.date_of_birth"
                    class="w-full"
                />
            </a-form-item>
        </a-form>
    </a-modal>

    <!-- Customer Details Modal -->
    <a-modal
        v-model:visible="showCustomerDetailsModal"
        title="Customer Details"
        :footer="null"
        width="600px"
    >
        <div v-if="selectedCustomer" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h4 class="font-medium text-gray-700">
                        Contact Information
                    </h4>
                    <p><strong>Name:</strong> {{ selectedCustomer.name }}</p>
                    <p>
                        <strong>Phone:</strong>
                        {{ selectedCustomer.phone || "N/A" }}
                    </p>
                    <p>
                        <strong>Email:</strong>
                        {{ selectedCustomer.email || "N/A" }}
                    </p>
                </div>
                <div>
                    <h4 class="font-medium text-gray-700">Loyalty Status</h4>
                    <p>
                        <strong>Tier:</strong>
                        {{ selectedCustomer.tier_info.name }}
                    </p>
                    <p>
                        <strong>Points:</strong>
                        {{
                            selectedCustomer.loyalty_points?.toLocaleString() ||
                            0
                        }}
                    </p>
                    <p>
                        <strong>Lifetime Spent:</strong> ₱{{
                            selectedCustomer.lifetime_spent?.toLocaleString() ||
                            0
                        }}
                    </p>
                    <p>
                        <strong>Total Purchases:</strong>
                        {{ selectedCustomer.total_purchases || 0 }}
                    </p>
                </div>
            </div>
        </div>
    </a-modal>

    <!-- Quantity Input Modal -->
    <a-modal
        v-model:visible="quantityModalVisible"
        title="Update Quantity"
        :width="400"
        @cancel="quantityModalVisible = false"
    >
        <div class="space-y-4">
            <!-- Product Info -->
            <div class="bg-gray-50 p-3 rounded">
                <h4 class="font-semibold text-gray-900">
                    {{ selectedOrder?.name }}
                </h4>
                <p class="text-sm text-gray-600">
                    Current: {{ selectedOrder?.quantity }} | Price: ₱{{
                        selectedOrder?.price
                    }}
                </p>
            </div>

            <!-- Quantity Input -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    New Quantity
                </label>
                <a-input-number
                    v-model:value="tempQuantity"
                    :min="0"
                    :max="999"
                    :precision="0"
                    size="large"
                    class="w-full"
                    placeholder="Enter quantity"
                    @keyup.enter="saveQuantity"
                />
                <p class="text-xs text-gray-500 mt-1">
                    Enter a number between 0 and 999
                </p>
            </div>

            <!-- Total Preview -->
            <div class="bg-green-50 p-3 rounded border border-green-200">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600">Total Amount:</span>
                    <span class="text-lg font-bold text-green-600">
                        ₱{{
                            (
                                tempQuantity * (selectedOrder?.price || 0)
                            ).toFixed(2)
                        }}
                    </span>
                </div>
            </div>
        </div>
        <template #footer>
            <!-- Clear button -->
            <a-button @click="cancelQuantity" size="large"> Cancel </a-button>

            <primary-button
                type="primary"
                @click="saveQuantity"
                :loading="loadingStates[selectedOrder?.id]"
                size="large"
            >
                Update Quantity
            </primary-button>
        </template>
    </a-modal>
</template>
<style>
/* Slide horizontal animation */
.slide-x-enter-active,
.slide-x-leave-active {
    transition: all 0.3s ease;
    position: absolute;
    width: 100%;
}
.slide-x-enter-from {
    opacity: 0;
    transform: translateX(100%);
}
.slide-x-leave-to {
    opacity: 0;
    transform: translateX(-100%);
}

/* Enhanced Quantity Controls Styling */
.quantity-display {
    @apply flex items-center justify-center min-w-[2rem] h-6 bg-gray-100 rounded border;
    @apply transition-all duration-200;
}

.quantity-display:hover {
    @apply bg-gray-200;
}

.quantity-display.editing {
    @apply bg-white border-blue-300 shadow-sm;
}

.quantity-button {
    @apply p-1 h-6 w-6 border-0 transition-all duration-200;
    @apply disabled:opacity-50 disabled:cursor-not-allowed;
}

.quantity-button.minus {
    @apply text-red-600 hover:text-red-700 hover:bg-red-50;
}

.quantity-button.plus {
    @apply text-green-600 hover:text-green-700 hover:bg-green-50;
}

/* Loading state for quantity display */
.quantity-loading {
    @apply animate-pulse;
}
</style>
